<!--
SPDX-FileCopyrightText: 2026 LibreCode Coop and contributors
SPDX-License-Identifier: AGPL-3.0-or-later
-->

# Operacoes financeiras do LibreFinance

O menu principal e organizado por tarefas, nao por divisao tecnica entre financeiro e contabilidade:

- **Visao geral:** saldos e evolucao.
- **A receber:** cobrancas, vendas, vencimentos, recebimentos e pagamentos parciais.
- **A pagar:** contas, compras, vencimentos e pagamentos parciais.
- **Bancos e caixa:** contas bancarias, movimentos, transferencias e conciliacao.
- **Relatorios:** saldos, fluxo de caixa e demonstrativos disponiveis.
- **Cadastros:** clientes, fornecedores, produtos, categorias e empresas.
- **Mais ferramentas:** lancamentos contabeis, plano de contas, impostos e NFS-e.

Nao ha menus paralelos para Faturas e compras, Financeiro e Contabilidade. As
ferramentas contabeis permanecem disponiveis, mas nao sao pre-requisito
para cadastrar uma cobranca.

## Uma operacao, duas formas de comecar

Em **A receber -> Nova cobranca** e **A pagar -> Nova conta**, escolha:

1. **Simples:** descricao, valor, vencimento e contato opcional. Cria somente
   um compromisso financeiro, sem fatura ou documento comercial.
2. **Detalhada:** contato, numero do documento, itens, quantidades, impostos,
   pais e vencimento. Cria um rascunho de venda/compra. Quando o gestor confirma,
   o servidor gera **um** compromisso vinculado na mesma transacao SQL.

Na propria area, alterne entre valores pendentes e documentos detalhados.
O compromisso exibe sua origem, permite abrir o documento e nao cria outro.
Os filtros de pagar/receber sao aplicados pelo servidor **antes da paginacao**.

## Receber e pagar sem duplicar movimentos

Um titulo simples ou um documento comercial confirmado pode apresentar a
acao **Registrar recebimento/pagamento**. E necessario informar conta de caixa/
bancaria ativa, classificacao de contrapartida, data e valor dentro do saldo.

O servidor registra um lancamento de partidas dobradas, vincula o pagamento ao
documento ou titulo existente e atualiza o saldo na mesma transacao SQL.
Chaves idempotentes impedem novas contabilizacoes em repeticoes identicas.
O usuario nao precisa digitar IDs de lancamentos.

Em contas avulsas, um pagamento ja contabilizado pode ser vinculado por
movimentacao existente. O sistema exige a mesma conta bancária, direcao,
valor e data, e reivindica o registro para impedir associacoes ou estornos
duplos. A transacao bancaria real continua fora do LibreFinance.

Se a movimentacao **ja foi contabilizada**, documentos comerciais mantem
**Vincular movimentacao ja registrada**, que nao cria outro lancamento.

O lancamento **nao transfere dinheiro real** nem concilia automaticamente o
extrato; conciliacao e operacao explicita.

## Regime de caixa, competencia e limites

Uma fatura com tributos ou retencoes **nao** pode ser contabilizada
automaticamente pelo fluxo simplificado de pagamento, pois o bruto nao deve
virar receita/despesa integralmente. O sistema bloqueia essa acao no
servidor e explica como vincular um movimento com as partidas tributarias
revisadas, em vez de criar um segundo lancamento.

A confirmacao de fatura comercial neste modelo e **operacional**: gera um titulo,
mas nao reconhece automaticamente receita/despesa por competencia. Logo, a acao
de pagamento automatico de fatura exige classificacao como **receita** no
recebimento ou **despesa** no pagamento, seguindo o regime de caixa. Usar
ativo/passivo de liquidacao sem competencia anterior distorceria o balancete.
Quem ja contabilizou o documento deve usar a associacao de movimentacao
existente, sem contabilizar novamente.

Para titulos avulsos, a contrapartida e escolhida explicitamente pelo operador;
um pagamento de emprestimo nao e presumido como despesa.

A baixa administrativa de titulo avulso permanece separada e **nao** cria
movimento bancario nem lancamento contabil. A API diferencia o valor liquidado
por diario do valor baixado apenas administrativamente, inclusive quando ambos
existem no mesmo titulo.

## Duplicacao, permissao e historico

- Cada documento confirmado origina um titulo, com relacionamento unico.
- O titulo vinculado nao aceita baixa administrativa nem cancelamento isolado.
- Uma partida contabil pode ser reivindicada por uma unica liquidacao
  comercial e nao e estornada silenciosamente depois de reivindicada.
- Valores parciais, saldos, autorizacao por espaco, concorrencia e idempotencia
  sao validados pelo PHP, independentemente da UI.
- Links antigos de faturas, vendas, compras e obrigacoes continuam navegaveis,
  mas sao direcionados a nova experiencia.
- Nao existe deteccao universal de duplicidade economica quando um usuario
  cria um titulo avulso para uma fatura preexistente com outra referencia.
  Coincidencia de valores e datas nao e prova de identidade; nao deve
  desencadear bloqueio automatico.

## Fora do escopo

Esta evolucao nao certifica emissao fiscal, pagamento bancario real,
contabilizacao por competencia completa nem migracao historica do Akaunting.
Cada uma exige controles de integridade e conciliacao proprios.
