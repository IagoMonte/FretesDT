<?php

use App\Http\Controllers\AnttController;
use App\Http\Controllers\AuthController;
use App\Http\Controllers\CadastroController;
use App\Http\Controllers\CotacaoController;
use App\Http\Controllers\EmbalagemController;
use App\Http\Controllers\EvolutionController;
use App\Http\Controllers\GrupoController;
use App\Http\Controllers\NotificacaoController;
use App\Http\Controllers\PainelController;
use App\Http\Controllers\ParteController;
use App\Http\Controllers\ProdutoController;
use App\Http\Controllers\ProgramacaoController;
use App\Http\Controllers\ReferenciaController;
use App\Http\Controllers\SincronizacaoController;
use App\Http\Controllers\TransportadoraController;
use App\Http\Controllers\UsuarioController;
use Illuminate\Support\Facades\Route;


Route::prefix('auth')->group(function () {
    Route::post('login', [AuthController::class, 'login'])->middleware('throttle:login');
    Route::post('refresh', [AuthController::class, 'refresh'])->middleware('throttle:30,1');
    Route::post('cadastro', [CadastroController::class, 'cadastrar'])->middleware('throttle:cadastro');
    Route::post('logout', [AuthController::class, 'logout'])->middleware('auth');
});

Route::middleware('auth')->group(function () {
    Route::get('me', [AuthController::class, 'me']);
    Route::get('dominios', [ReferenciaController::class, 'dominios']);
    Route::get('painel', PainelController::class)->middleware('can:PAINEL.ver');

    Route::middleware('pode.algum:TRANSPORTADORA.ver,NEGOCIACAO.ENCAMINHAR,NEGOCIACAO.FECHAR')->group(function () {
        Route::get('transportadoras', [TransportadoraController::class, 'index']);
        Route::get('transportadoras/{id}', [TransportadoraController::class, 'show']);
    });
    Route::post('transportadoras', [TransportadoraController::class, 'store'])->middleware('can:TRANSPORTADORA.inserir');
    Route::patch('transportadoras/{id}', [TransportadoraController::class, 'update'])->middleware('can:TRANSPORTADORA.alterar');
    Route::post('transportadoras/{id}/inativar', [TransportadoraController::class, 'inativar'])->middleware('can:TRANSPORTADORA.cancelar');
    Route::post('transportadoras/{id}/ativar', [TransportadoraController::class, 'ativar'])->middleware('can:TRANSPORTADORA.cancelar');

    Route::middleware('pode.algum:PRODUTO.ver,COTACAO.inserir,PROGRAMACAO.inserir')->group(function () {
        Route::get('produtos', [ProdutoController::class, 'index']);
        Route::get('produtos/{id}', [ProdutoController::class, 'show']);
    });
    Route::post('produtos', [ProdutoController::class, 'store'])->middleware('can:PRODUTO.inserir');
    Route::patch('produtos/{id}', [ProdutoController::class, 'update'])->middleware('can:PRODUTO.alterar');
    Route::post('produtos/{id}/inativar', [ProdutoController::class, 'inativar'])->middleware('can:PRODUTO.cancelar');
    Route::post('produtos/{id}/ativar', [ProdutoController::class, 'ativar'])->middleware('can:PRODUTO.cancelar');

    Route::get('embalagens', [EmbalagemController::class, 'index'])->middleware('pode.algum:EMBALAGEM.ver,COTACAO.inserir,PROGRAMACAO.inserir');
    Route::post('embalagens', [EmbalagemController::class, 'store'])->middleware('can:EMBALAGEM.inserir');
    Route::patch('embalagens/{codigo}', [EmbalagemController::class, 'update'])->middleware('can:EMBALAGEM.alterar');
    Route::post('embalagens/{codigo}/inativar', [EmbalagemController::class, 'inativar'])->middleware('can:EMBALAGEM.cancelar');
    Route::post('embalagens/{codigo}/ativar', [EmbalagemController::class, 'ativar'])->middleware('can:EMBALAGEM.cancelar');

    Route::get('antt/coeficientes', [AnttController::class, 'index'])->middleware('can:ANTT.ver');
    Route::post('antt/coeficientes', [AnttController::class, 'store'])->middleware('can:ANTT.inserir');
    Route::patch('antt/coeficientes/{id}', [AnttController::class, 'update'])->middleware('can:ANTT.alterar');
    Route::post('antt/coeficientes/{id}/inativar', [AnttController::class, 'inativar'])->middleware('can:ANTT.cancelar');
    Route::post('antt/coeficientes/{id}/ativar', [AnttController::class, 'ativar'])->middleware('can:ANTT.cancelar');
    Route::get('distancias/consulta', [AnttController::class, 'distancia'])->middleware('pode.algum:ANTT.ver,COTACAO.inserir');
    Route::get('antt/frete-minimo', [AnttController::class, 'freteMinimo'])->middleware('pode.algum:ANTT.ver,COTACAO.inserir');

    Route::get('sincronizacao', [SincronizacaoController::class, 'index'])->middleware('can:SINCRONIZACAO.ver');
    Route::get('sincronizacao/pendencias', [SincronizacaoController::class, 'pendencias'])->middleware('can:SINCRONIZACAO.ver');
    Route::post('sincronizacao/{entidade}/executar', [SincronizacaoController::class, 'executar'])->middleware('can:SINCRONIZACAO.EXECUTAR');
    Route::post('sincronizacao/{entidade}/registro', [SincronizacaoController::class, 'registro'])->middleware('can:SINCRONIZACAO.EXECUTAR');

    Route::get('partes', [ParteController::class, 'index'])->middleware('pode.algum:PARTE.ver,COTACAO.inserir,PROGRAMACAO.inserir');
    Route::post('clientes', [ParteController::class, 'storeCliente'])->middleware('can:PARTE.inserir');
    Route::post('fornecedores', [ParteController::class, 'storeFornecedor'])->middleware('can:PARTE.inserir');
    Route::patch('clientes/{id}', [ParteController::class, 'updateCliente'])->middleware('can:PARTE.alterar');
    Route::patch('fornecedores/{id}', [ParteController::class, 'updateFornecedor'])->middleware('can:PARTE.alterar');

    Route::get('usuarios', [UsuarioController::class, 'index'])->middleware('can:USUARIO.ver');
    Route::get('usuarios/{id}', [UsuarioController::class, 'show'])->middleware('can:USUARIO.ver');
    Route::post('usuarios', [UsuarioController::class, 'store'])->middleware('can:USUARIO.inserir');
    Route::patch('usuarios/{id}', [UsuarioController::class, 'update'])->middleware('can:USUARIO.alterar');
    Route::post('usuarios/{id}/bloquear', [UsuarioController::class, 'bloquear'])->middleware('can:USUARIO.LIBERAR_ACESSO');
    Route::post('usuarios/{id}/ativar', [UsuarioController::class, 'ativar'])->middleware('can:USUARIO.LIBERAR_ACESSO');
    Route::get('cadastros', [CadastroController::class, 'index'])->middleware('can:USUARIO.APROVAR_CADASTRO');
    Route::post('cadastros/{id}/aprovar', [CadastroController::class, 'aprovar'])->middleware('can:USUARIO.APROVAR_CADASTRO');
    Route::post('cadastros/{id}/recusar', [CadastroController::class, 'recusar'])->middleware('can:USUARIO.APROVAR_CADASTRO');
    Route::get('grupos', [ReferenciaController::class, 'grupos'])->middleware('can:USUARIO.ver');
    Route::get('grupos/catalogo', [GrupoController::class, 'catalogo'])->middleware('can:GRUPO.ver');
    Route::get('grupos/detalhes', [GrupoController::class, 'detalhes'])->middleware('can:GRUPO.ver');
    Route::post('grupos', [GrupoController::class, 'store'])->middleware('can:GRUPO.inserir');
    Route::patch('grupos/{id}', [GrupoController::class, 'update'])->middleware('can:GRUPO.alterar');
    Route::post('grupos/{id}/inativar', [GrupoController::class, 'inativar'])->middleware('can:GRUPO.alterar');
    Route::post('grupos/{id}/ativar', [GrupoController::class, 'ativar'])->middleware('can:GRUPO.alterar');
    Route::get('vendedores', [ReferenciaController::class, 'vendedores'])->middleware('pode.algum:USUARIO.ver,COTACAO.inserir,PROGRAMACAO.inserir');

    Route::get('cotacoes', [CotacaoController::class, 'index'])->middleware('can:COTACAO.ver');
    Route::get('cotacoes/anterior', [CotacaoController::class, 'anterior'])->middleware('can:COTACAO.ver');
    Route::get('cotacoes/{id}', [CotacaoController::class, 'show'])->middleware('can:COTACAO.ver');
    Route::post('cotacoes', [CotacaoController::class, 'store'])->middleware('can:COTACAO.inserir');
    Route::patch('cotacoes/{id}', [CotacaoController::class, 'update'])->middleware('can:COTACAO.alterar');
    Route::post('cotacoes/{id}/encaminhar', [CotacaoController::class, 'encaminhar'])->middleware('can:NEGOCIACAO.ENCAMINHAR');
    Route::post('cotacoes/{id}/respostas/{respostaId}/responder', [CotacaoController::class, 'responder'])->middleware('can:NEGOCIACAO.ver');
    Route::post('cotacoes/{id}/respostas/{respostaId}/declinar', [CotacaoController::class, 'declinar'])->middleware('can:NEGOCIACAO.ver');
    Route::post('cotacoes/{id}/fechar', [CotacaoController::class, 'fechar'])->middleware('can:NEGOCIACAO.FECHAR');
    Route::post('cotacoes/{id}/encerrar', [CotacaoController::class, 'encerrar'])->middleware('can:NEGOCIACAO.FECHAR');
    Route::post('cotacoes/{id}/cancelar',[CotacaoController::class, 'cancelar'])->middleware('can:COTACAO.cancelar');

    Route::get('notificacoes/preferencias', [NotificacaoController::class, 'preferencias'])->middleware('can:NOTIFICACAO.ver');
    Route::post('notificacoes/preferencias', [NotificacaoController::class, 'salvarPreferencias'])->middleware('can:NOTIFICACAO.alterar');
    Route::get('notificacoes/historico', [NotificacaoController::class, 'historico'])->middleware('can:NOTIFICACAO.ver');
    Route::post('notificacoes/teste', [NotificacaoController::class, 'teste'])->middleware('can:NOTIFICACAO.ENVIAR_TESTE');
    Route::post('notificacoes/{id}/reenviar', [NotificacaoController::class, 'reenviar'])->middleware('can:NOTIFICACAO.REENVIAR');

    Route::get('evolution/status', [EvolutionController::class, 'status'])->middleware('can:NOTIFICACAO.ver');
    Route::get('evolution/qr', [EvolutionController::class, 'qr'])->middleware('can:NOTIFICACAO.ver');
    Route::post('evolution/teste', [EvolutionController::class, 'teste'])->middleware('can:NOTIFICACAO.ENVIAR_TESTE');

    Route::get('programacoes', [ProgramacaoController::class, 'index'])->middleware('can:PROGRAMACAO.ver');
    Route::get('programacoes/{id}', [ProgramacaoController::class, 'show'])->middleware('can:PROGRAMACAO.ver');
    Route::post('programacoes', [ProgramacaoController::class, 'store'])->middleware('can:PROGRAMACAO.inserir');
    Route::patch('programacoes/{id}', [ProgramacaoController::class, 'update']);
    Route::post('programacoes/{id}/status', [ProgramacaoController::class, 'status'])->middleware('can:PROGRAMACAO.ALTERAR_STATUS');
    Route::post('programacoes/{id}/entregar', [ProgramacaoController::class, 'entregar'])->middleware('can:PROGRAMACAO.CONFIRMAR_ENTREGA');
    Route::post('programacoes/{id}/cancelar', [ProgramacaoController::class, 'cancelar'])->middleware('can:PROGRAMACAO.cancelar');
});
