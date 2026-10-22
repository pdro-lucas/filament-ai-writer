# Administração de IA, créditos e proteção contra abuso

Status: etapas 1 e 2 concluídas. Etapa 2 com Dique APPROVED FINAL, zero blockers e 16/16 hashes conferidos; suites Filament 4.15.3 e 5.10.3 verdes (146 testes/1072 assertions), probes reais MySQL 8.4.11 e Workbench build preservando a demo. PostgreSQL não exercitado; QA visual é do usuário. Proteção contra abuso (etapa 3) e experiência completa (etapa 4) permanecem pendentes. Relatório de implementação: `/tmp/filament-stage2-implementation.md`; revisão final: `/tmp/filament-stage2-source-review.md`.

## Objetivo

Permitir que uma aplicação Filament controle a disponibilidade de IA, distribua créditos por usuário e contenha abuso sem exigir um model `User`, colunas ou arquitetura de tenancy específicos.

## Decisões aprovadas

| Tema | Regra |
| --- | --- |
| Unidade | Uma geração entregue ao campo consome um crédito inteiro. Tokens e custo monetário continuam como métricas separadas. |
| Escopo padrão | Usuário autenticado e painel. O desenvolvedor pode configurar compartilhamento entre painéis ou contexto de tenant. |
| Identidade | Tipo e identificador opaco do ator, associados ao escopo resolvido no servidor. Sem coluna `credits` obrigatória no usuário. |
| Ativação | Cotas desativadas por padrão. A chave de habilitar/desabilitar IA é independente das cotas. As permissões existentes continuam sendo aplicadas. |
| Ativação e reativação | Sem desconto retroativo por gerações admitidas com cotas desligadas. Reativar preserva o consumo já contabilizado no mesmo período; não redefine a cota. |
| Política | Limite e calendário padrão; cada usuário pode ter exceção no limite, mas não um calendário próprio. |
| Recovery | Deadline congelado na admissão: timeout de geração + margem técnica configurável (padrão 30 segundos). Ao expirar, liberar uma vez sob lock e impedir definitivamente qualquer entrega tardia, mesmo antes da manutenção. Também vale para operações gerenciadas sem quota. |
| Calendários | Dia 00h, semana segunda 00h, mês dia 1 no fuso da aplicação. Mudanças preservam o encerramento finito atual; manual→calendário encerra no próximo corte do novo calendário, preservando consumo até lá. |
| Renovação | Administrador pode escolher manual, diária, semanal ou mensal, seguindo calendário e fuso da aplicação. |
| Acúmulo | Não acumula sobra entre períodos. A renovação restaura o limite aplicável. |
| Alteração de limite | Preserva consumo do período. Consumiu 60 e o limite passa a 150: 90 disponíveis. O limite passa a 50: nenhum disponível; o histórico continua registrando 60. |
| Falha | Reserva antes da chamada. Se o texto não for entregue, libera a reserva; uma falha pode continuar tendo custo registrado. |
| Bloqueio | Administrador pode bloquear/desbloquear o uso da IA por usuário, independentemente da cota disponível. |
| Gerações em voo | Desligamento de IA ou bloqueio impedem novas admissões. Gerações já admitidas concluem normalmente, preservando tratamento de falhas existente; não cancelar nem descartar o resultado por essa alteração. |
| Abuso | Proteções de frequência, simultaneidade e falhas repetidas configuráveis na administração, inclusive com cotas desativadas. |
| Administração | Alterar limite, conceder créditos extras e redefinir cota são ações distintas e auditadas, com administrador, data e motivo. |

Os controles de créditos não constituem um teto de custo financeiro: uma geração longa pode custar mais que uma curta.

Com limite de 100, um usuário que gerou 70 vezes antes da primeira ativação começa com 100 disponíveis. Se consumiu 20 créditos, usou a IA livremente com as cotas desligadas e elas foram reativadas no mesmo período, continua com 80 disponíveis. Somente gerações admitidas com cotas ativas podem consumir crédito; o histórico de uso livre continua disponível para estatísticas, sem virar débito retroativo.

## Experiência administrativa

Uma página de configuração protegida pela autorização administrativa do plugin deve agrupar:

1. **Disponibilidade:** habilitação da IA no escopo gerenciado e bloqueios individuais.
2. **Créditos:** ativação das cotas, limite padrão, renovação e limites individuais. Créditos extras do período e redefinição manual têm ações próprias e motivo.
3. **Proteção contra abuso:** frequência por intervalo, simultaneidade e proteção por falhas repetidas. Tentativas com falha também entram nessa proteção.
4. **Usuários:** consumo, reservas em andamento, disponível, limite efetivo, bloqueio e próxima renovação.
5. **Auditoria:** alterações de configuração, limites, bloqueios, concessões e redefinições, sem apagar o histórico de geração.

O usuário que pode gerar deve conseguir consultar seu próprio disponível, consumo e próxima renovação, sem receber acesso às estatísticas ou à administração de outros usuários.

A interface deve explicar o motivo de uma recusa: IA desativada, bloqueio, créditos esgotados, frequência excedida, simultaneidade ou pausa após falhas. Autorizações e recusas são verificadas no servidor, inclusive em submissões de modais já abertos.

## Contrato de consumo

O saldo de uma cota ativa considera limite efetivo, concessões válidas do período, consumo confirmado e reservas ativas. O disponível apresentado nunca é negativo. Valores históricos não são truncados quando o limite é reduzido.

O módulo de admissão deve centralizar as decisões usadas por todas as ações e pelo caminho compartilhado de geração:

1. Resolver ator, painel, escopo e política no servidor; verificar permissões, habilitação e bloqueio.
2. Verificar proteções contra abuso e reservar atomicamente um crédito, quando as cotas estiverem ativas, antes de qualquer chamada ao provider.
3. Associar a reserva à identidade da operação e ao período de admissão. A mesma operação não inicia chamadas ou débitos duplicados. Operações sem quota preservam esse caminho sem débito mesmo após ativação da política; reset/renovação mantêm a liquidação no saldo de origem.
4. Executar HTTP fora de transações e locks de banco de longa duração.
5. Confirmar um débito somente quando a entrega local for confirmada. Liquidação da cota e confirmação da entrega precisam compartilhar a mesma garantia transacional; uma falha na entrega ou na persistência não pode deixar texto aplicado e contabilidade incompatível.
6. Liberar a reserva em falhas conhecidas sem entrega. Liquidação e liberação precisam ser idempotentes.

Uma geração parcialmente recebida, recusada ou truncada, sem entrega ao campo, não consome crédito. Custos e resultados do provider permanecem no ledger existente.

Requisições concorrentes precisam disputar a mesma cota: com um crédito disponível, não podem ser admitidas duas gerações. Tentativas recusadas pela proteção não podem chegar ao provider. Falhas de armazenamento dos controles devem resultar em recusa segura quando a política estiver ativa.

Reservas interrompidas por encerramento do processo expiram após o deadline congelado. `ai-writer:recover-operations` reconcilia operações expiradas; os caminhos de admissão, resposta e entrega também verificam o prazo independentemente do comando. A expiração é terminal e libera a reserva de origem uma única vez sob o mesmo lock da entrega. Metadados conhecidos de uma resposta tardia podem enriquecer somente histórico existente, preservando a primeira resposta e sem reabrir operação, refazer débito ou recriar uso podado. Não presumir que timeout significa ausência de custo remoto, nem liberar uma reserva e permitir uma conclusão tardia duplicar consumo ou entrega. O desenho dessa recuperação é um critério de entrada para implementar o módulo.

## Integração e isolamento

- Reutilizar as permissões de uso e administração do `AiWriterPlugin`; estar autenticado ou ver estatísticas não concede administração.
- A aplicação define a resolução de escopo e identidade; o administrador define as políticas dentro do escopo que pode administrar. A escolha de escopo não é um campo livre enviado pelo navegador.
- Cadastro/listagem de usuários deve funcionar por uma integração explícita com a aplicação. Não assumir tabela, classe, coluna de nome, tipo numérico de ID ou model Eloquent universal.
- Resolver escopo uma única vez por operação e impedir alterações de painel/guard/tenant em requisições subsequentes de contornar o isolamento.
- Compartilhamento entre painéis e tenancy precisam de identidade de política e autorização administrativa inequívocas; não inferir uma política a partir do último painel ativo.
- Persistir configurações, ajustes e contabilidade em estruturas do plugin. Publicar migrations conforme o padrão existente; não executar migrations automaticamente na aplicação consumidora.
- Não derivar o saldo somente da história que pode ser removida pela retenção. Limpar o histórico não pode restaurar créditos ou permitir abuso.
- Preservar a proteção contra operações duplicadas também após a retenção. Hoje ela depende das linhas de uso; a implementação dos controles precisa definir uma identidade persistente ou uma janela explícita de validade das operações antes de depender dessa deduplicação.

## Plano de entrega

### 1. Persistência, escopos e administração básica

Implementação: `Controls` centraliza disponibilidade, bloqueio e auditoria; `ControlContext` congela painel/guard/escopo/administrador. `resolveScopeUsing` e `actorDirectoryUsing(search, resolve)` são integrações explícitas do host, documentadas no README. A página exige administração e recusa troca de contexto em requisições posteriores. Migration nova publicável, sem execução automática. Testes, QA visual e revisão efetivamente realizados constam do relatório de entrega; source/QA aprovados, contrato inflight fechado e revisão final do Dique aprovada sem blockers.

Definir e revisar o contrato de escopo/identidade, a integração de usuários, armazenamento de políticas e auditoria. Implementar habilitação e bloqueio usando a permissão administrativa já existente. Preparar a Workbench com exemplos de integração independentes do usuário da aplicação consumidora.

Critério: um usuário não autorizado não administra configurações; um bloqueio ou desligamento impede chamada ao provider; escopos não se misturam.

### 2. Créditos e renovação

Concluída: `Credits` centraliza política, saldo próprio/admin, reservas, liquidação/refund, renovação, extras/reset auditados e recuperação com deadline/fencing. `UsageRecorder` compartilha admissão e entrega transacional na mesma conexão, com identidade durável independente de retenção e preservação de medições tardias. Migration 000003 publicável; cutover quiescente documentado, sem suporte a rolling misto. Verificações e limites efetivos constam do relatório acima.

Implementar reserva, confirmação/liberação, renovação, exceções de limite, concessões e redefinição manual. Integrar à entrega transacional existente sem manter locks durante HTTP.

Critério: último crédito sob concorrência, falhas, duplicatas, mudanças de limite e viradas de período mantêm saldo e histórico corretos.

### 3. Proteção contra abuso

Implementar frequência, simultaneidade e pausa por falhas repetidas, com configuração administrativa. Esses controles operam independentemente da ativação de cotas e não desaparecem com a poda do histórico.

Critério: chamadas paralelas e tentativas que falham não contornam a proteção; recusas não chamam o provider; a recuperação após a janela/pausa é verificável.

### 4. Experiência completa e compatibilidade

Completar a página administrativa, a visão de créditos do usuário e os exemplos/documentação de integração. Verificar a interface real, permissões em requisições posteriores, Filament 4/5 e as garantias de concorrência nos bancos declarados como suportados.

## Critérios técnicos que precisam estar resolvidos antes da implementação correspondente

Estes pontos não alteram as decisões de produto acima; precisam de um desenho concreto e evidência, em vez de suposições no código:

- Datas de corte diárias, semanais e mensais, horário de verão e efeito de mudar o calendário durante um período.
- Destino de operações já admitidas quando ocorre renovação, redefinição ou alteração de limite (etapas futuras). Para desligamento/bloqueio, a decisão já está fechada: gerações admitidas concluem e novas admissões são recusadas.
- Recuperação de reservas interrompidas, limites máximos de execução e tratamento de conclusões tardias.
- Valores iniciais e validação das proteções contra abuso; ativar um controle deve exigir configuração válida, sem inventar silenciosamente limites de negócio.
- Descoberta e seleção de usuários que ainda não geraram conteúdo, usando a integração da aplicação sem assumir seu schema.
- Compatibilidade de atomicidade, locks e retries nos bancos suportados; um teste SQLite isolado não comprova concorrência em toda a matriz.

Se alguma escolha nesses pontos mudar um comportamento visível não definido, retornar ao usuário antes de implementar essa parte.

## Verificação

Auditar primeiro os testes de autorização, geração, ledger, retenção e Workbench. Estender cobertura vizinha e manter novos testes apenas para regras sensíveis: identidade/escopo, permissões, consumo, reserva, renovação, falhas, idempotência, concorrência, abuso e auditoria. Sem nova infraestrutura de teste apenas para aparência.

A entrega deve registrar comandos executados, cenários reais na Workbench, novos testes mantidos, bancos/versões realmente exercitados e limitações. Esta especificação não equivale a uma implementação ou a uma garantia de compatibilidade já verificada.

## Base existente inspecionada

- `src/AiWriterPlugin.php`: autorizações distintas de uso, estatísticas e administração.
- `src/Generation/Generation.php`: caminho compartilhado que verifica autorização antes da geração.
- `src/Data/GenerationContext.php`: captura de ator, painel e operação no servidor.
- `src/Usage/UsageRecorder.php`: admissão, resposta recebida e entrega local transacional, com restauração em falha.
- `src/Usage/UsagePruner.php`: retenção opt-in; histórico removível não pode ser a única fonte de quota.

A memória histórica recuperada também recomenda reserva atômica antes do provider e liquidação idempotente. Essas recomendações foram comparadas com o código atual; os controles de quotas continuam pendentes.

Decisão aprovada para etapa 3: falhas em janela configurável, sucesso não zera outras falhas recentes; thresholds, janela e cooldown administráveis, proteção off por padrão. Não implementado na etapa 2.
