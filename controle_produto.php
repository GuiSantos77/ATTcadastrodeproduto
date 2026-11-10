<?php
session_start();
require __DIR__ . '/conexao.php';

const ESTOQUE_MINIMO = 5;

$categorias = ['Informática', 'Eletrônicos', 'Móveis', 'Papelaria', 'Serviços', 'Outros'];

function e($valor)
{
    return htmlspecialchars((string) $valor, ENT_QUOTES, 'UTF-8');
}

function moeda($valor)
{
    return 'R$ ' . number_format((float) $valor, 2, ',', '.');
}

function aviso($tipo, $texto)
{
    $_SESSION['aviso'] = ['tipo' => $tipo, 'texto' => $texto];
}

function voltar()
{
    header('Location: controle_produto.php');
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $acao = $_POST['acao'] ?? '';
    $descricao = trim($_POST['descricao'] ?? '');
    $quantidade = (int) ($_POST['estoque'] ?? 0);

    if ($acao === 'cadastrar') {
        $categoria = $_POST['categoria'] ?? '';
        $compra = (float) ($_POST['valor_compra'] ?? 0);
        $venda = (float) ($_POST['valor_venda'] ?? 0);

        if ($descricao === '' || !in_array($categoria, $categorias, true)) {
            aviso('erro', 'Informe a descrição e escolha uma categoria.');
            voltar();
        }
        if ($compra <= 0 || $venda <= 0) {
            aviso('erro', 'Os valores de compra e venda precisam ser maiores que zero.');
            voltar();
        }
        if ($quantidade < 0) {
            aviso('erro', 'A quantidade em estoque não pode ser negativa.');
            voltar();
        }

        $busca = $pdo->prepare('SELECT id FROM produtos WHERE descricao = ?');
        $busca->execute([$descricao]);
        $existente = $busca->fetch();

        if ($existente) {
            $pdo->prepare(
                'UPDATE produtos
                 SET categoria = ?, valor_compra = ?, valor_venda = ?, estoque = estoque + ?
                 WHERE id = ?'
            )->execute([$categoria, $compra, $venda, $quantidade, $existente['id']]);
            aviso('ok', 'Produto já existia: estoque somado e valores atualizados.');
        } else {
            $pdo->prepare(
                'INSERT INTO produtos (descricao, categoria, valor_compra, valor_venda, estoque)
                 VALUES (?, ?, ?, ?, ?)'
            )->execute([$descricao, $categoria, $compra, $venda, $quantidade]);
            aviso('ok', 'Produto cadastrado com sucesso.');
        }
        voltar();
    }

    if ($acao === 'vender') {
        if ($descricao === '') {
            aviso('erro', 'Informe a descrição do produto que será vendido.');
            voltar();
        }
        if ($quantidade <= 0) {
            $quantidade = 1;
        }

        try {
            $pdo->beginTransaction();

            $busca = $pdo->prepare('SELECT * FROM produtos WHERE descricao = ? FOR UPDATE');
            $busca->execute([$descricao]);
            $produto = $busca->fetch();

            if (!$produto) {
                $pdo->rollBack();
                aviso('erro', 'Produto não encontrado no inventário.');
                voltar();
            }
            if ($produto['estoque'] < $quantidade) {
                $pdo->rollBack();
                aviso('erro', 'Estoque insuficiente. Disponível: ' . $produto['estoque'] . '.');
                voltar();
            }

            $total = $produto['valor_venda'] * $quantidade;
            $lucro = ($produto['valor_venda'] - $produto['valor_compra']) * $quantidade;

            $pdo->prepare('UPDATE produtos SET estoque = estoque - ? WHERE id = ?')
                ->execute([$quantidade, $produto['id']]);

            $pdo->prepare(
                'INSERT INTO vendas (produto_id, descricao, quantidade, valor_total, lucro)
                 VALUES (?, ?, ?, ?, ?)'
            )->execute([$produto['id'], $produto['descricao'], $quantidade, $total, $lucro]);

            $pdo->commit();

            $restante = $produto['estoque'] - $quantidade;
            if ($restante <= ESTOQUE_MINIMO) {
                aviso('alerta', 'Venda registrada. Atenção: restam apenas ' . $restante . ' unidade(s) em estoque.');
            } else {
                aviso('ok', 'Venda de ' . $quantidade . ' unidade(s) registrada: ' . moeda($total) . '.');
            }
        } catch (Throwable $erro) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            aviso('erro', 'Não foi possível registrar a venda.');
        }
        voltar();
    }

    if ($acao === 'excluir') {
        $id = (int) ($_POST['id'] ?? 0);
        $pdo->prepare('DELETE FROM produtos WHERE id = ?')->execute([$id]);
        aviso('ok', 'Produto removido do inventário.');
        voltar();
    }

    voltar();
}

$aviso = $_SESSION['aviso'] ?? null;
unset($_SESSION['aviso']);

$termo = trim($_GET['q'] ?? '');
if ($termo !== '') {
    $consulta = $pdo->prepare(
        'SELECT * FROM produtos WHERE descricao LIKE ? OR categoria LIKE ? ORDER BY descricao'
    );
    $consulta->execute(["%$termo%", "%$termo%"]);
} else {
    $consulta = $pdo->query('SELECT * FROM produtos ORDER BY descricao');
}
$produtos = $consulta->fetchAll();

$resumo = $pdo->query(
    'SELECT COALESCE(SUM(valor_total), 0) AS receita, COALESCE(SUM(lucro), 0) AS lucro FROM vendas'
)->fetch();

$emEstoque = $pdo->query(
    'SELECT COALESCE(SUM(valor_compra * estoque), 0) AS capital FROM produtos'
)->fetch();
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Capitaly | Cadastro de Produto</title>
    <link rel="stylesheet" href="estilo.css">
</head>
<body>
    <header class="topo">
        <div class="topo-conteudo">
            <span class="marca">Capitaly</span>
            <span class="sub">Gestão financeira</span>
        </div>
    </header>

    <main class="pagina">
        <section class="indicadores">
            <div class="indicador">
                <span>Receita de vendas</span>
                <strong><?= moeda($resumo['receita']) ?></strong>
            </div>
            <div class="indicador">
                <span>Lucro realizado</span>
                <strong class="positivo"><?= moeda($resumo['lucro']) ?></strong>
            </div>
            <div class="indicador">
                <span>Capital em estoque</span>
                <strong><?= moeda($emEstoque['capital']) ?></strong>
            </div>
        </section>

        <?php if ($aviso): ?>
            <div class="aviso <?= e($aviso['tipo']) ?>"><?= e($aviso['texto']) ?></div>
        <?php endif; ?>

        <section class="cartao">
            <h2 class="titulo">
                <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M3 7l9-4 9 4-9 4-9-4zm0 4l9 4 9-4m-18 4l9 4 9-4"/></svg>
                Cadastro de Produto
            </h2>

            <form method="post" class="formulario">
                <div class="campo">
                    <label for="descricao">Descrição</label>
                    <input type="text" id="descricao" name="descricao" maxlength="120" placeholder="Ex.: Notebook Samsung">
                </div>
                <div class="campo">
                    <label for="categoria">Categoria</label>
                    <select id="categoria" name="categoria">
                        <?php foreach ($categorias as $cat): ?>
                            <option value="<?= e($cat) ?>"><?= e($cat) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="campo">
                    <label for="valor_compra">Valor Compra (R$)</label>
                    <input type="number" id="valor_compra" name="valor_compra" step="0.01" min="0" placeholder="0,00">
                </div>
                <div class="campo">
                    <label for="valor_venda">Valor Venda (R$)</label>
                    <input type="number" id="valor_venda" name="valor_venda" step="0.01" min="0" placeholder="0,00">
                </div>
                <div class="campo estreito">
                    <label for="estoque">Qtd. Estoque</label>
                    <input type="number" id="estoque" name="estoque" min="0" placeholder="0">
                </div>
                <div class="botoes">
                    <button type="submit" name="acao" value="cadastrar" class="btn primario">Cadastrar Produto</button>
                    <button type="submit" name="acao" value="vender" class="btn secundario">Vender Produto</button>
                </div>
            </form>
        </section>

        <section class="cartao">
            <div class="cabecalho-inventario">
                <h2 class="titulo">
                    <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M4 5h16M4 12h16M4 19h16"/></svg>
                    Inventário
                </h2>
                <form method="get" class="busca">
                    <input type="text" name="q" value="<?= e($termo) ?>" placeholder="Buscar por descrição ou categoria">
                    <button type="submit" aria-label="Buscar">
                        <svg viewBox="0 0 24 24" aria-hidden="true"><circle cx="11" cy="11" r="6.5"/><path d="M16 16l5 5"/></svg>
                    </button>
                </form>
            </div>

            <div class="tabela-area">
                <table>
                    <thead>
                        <tr>
                            <th>Descrição</th>
                            <th>Categoria</th>
                            <th>Venda</th>
                            <th>Lucro Unit.</th>
                            <th>Estoque</th>
                            <th class="centro">Ações</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (!$produtos): ?>
                            <tr><td colspan="6" class="vazio">Nenhum produto encontrado.</td></tr>
                        <?php endif; ?>
                        <?php foreach ($produtos as $p):
                            $lucroUnit = $p['valor_venda'] - $p['valor_compra'];
                            $baixo = $p['estoque'] <= ESTOQUE_MINIMO;
                        ?>
                            <tr class="<?= $baixo ? 'estoque-baixo' : '' ?>">
                                <td><?= e($p['descricao']) ?></td>
                                <td><?= e($p['categoria']) ?></td>
                                <td><?= moeda($p['valor_venda']) ?></td>
                                <td class="<?= $lucroUnit >= 0 ? 'positivo' : 'negativo' ?>"><?= moeda($lucroUnit) ?></td>
                                <td>
                                    <span class="selo <?= $baixo ? 'selo-alerta' : '' ?>"><?= (int) $p['estoque'] ?></span>
                                    <?php if ($baixo): ?><small class="legenda">baixo</small><?php endif; ?>
                                </td>
                                <td class="centro">
                                    <form method="post" onsubmit="return confirm('Remover este produto?');">
                                        <input type="hidden" name="acao" value="excluir">
                                        <input type="hidden" name="id" value="<?= (int) $p['id'] ?>">
                                        <button type="submit" class="remover" aria-label="Remover produto">
                                            <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M7 7l10 10M17 7L7 17"/></svg>
                                        </button>
                                    </form>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </section>
    </main>
</body>
</html>
