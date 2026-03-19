<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Models\Site;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

class SiteController extends Controller
{
    /**
     * Redireciona para a rota desejada.
     */
    public function getSitesById(Request $request)
    {
        $validator = Validator::make(
            $request->all(),
            [
                'id' => 'required|exists:clientes,id'
            ],
            [
                'required' => 'O campo :attribute é obrigatório.',
                'exists' => 'O :attribute não existe no banco de dados.'
            ]
        );

        if ($validator->fails()) {
            return response()->json(['mensagem' => 'Parâmetros inválidos na requisição.', 'erros' => $validator->errors()->all(), 'info' => ''], 422);
        }

        try {;
            $listar = Site::where('id', $request->id)->with('user')
                ->first()
                ->makeHidden(['deleted_at', 'created_at', 'updated_at']);

            return response()->json(['mensagem' => 'Registro encontrado.', 'erros' => '', 'info' => $listar], 200);
        } catch (\Exception $e) {
            return response()->json(['mensagem' => 'Erro ao buscar sites por ID.', 'erros' => $e->getMessage(), 'info' => ''], 500);
        }
    }

    /**
     * Display a listing of the resource.
     */
    public function index()
    {
        try {
            $sites = Site::with('user')
                ->get()
                ->makeHidden(['deleted_at', 'created_at', 'updated_at']);


            $listar = $sites->map(function ($site) {
                return [
                    'id' => $site->id,
                    'cliente' => $site->user->name,
                    'resumo' => $site->resumo,
                    'responsavel' => $site->user->name,
                    'sobrenome' => $site->user->sobrenome,
                    'email' => $site->user->email,
                    'cpf_cnpj' => $site->user->cpf_cnpj,
                    'telefone' => $site->user->telefone,
                    'dominio' => $site->dominio,
                    'acesso_email' => $site->acesso_email,
                    'email_profissional' => $site->email_profissional,
                    'drive' => $site->drive,
                    'youtube' => $site->youtube,
                    'facebook' => $site->facebook,
                    'linkedin' => $site->linkedin,
                    'gmail' => $site->gmail,
                    'instagram' => $site->instagram,
                    'linktree' => $site->linktree,
                    'info_adicionais' => $site->info_adicionais,
                    'registro_dominio' => $site->registro_dominio,
                    'vencimento_dominio' => $site->vencimento_dominio,
                    'usuario_dominio' => $site->usuario_dominio,
                    'senha_dominio' => $site->senha_dominio,
                    'hospedagem' => $site->hospedagem,
                    'vencimento_hospedagem' => $site->vencimento_hospedagem,
                    'usuario_hospedagem' => $site->usuario_hospedagem,
                    'senha_hospedagem' => $site->senha_hospedagem,
                    'dns_primario' => $site->dns_primario,
                    'dns_secundario' => $site->dns_secundario,
                    'ftp' => $site->ftp,
                    'usuario_ftp' => $site->usuario_ftp,
                    'senha_ftp' => $site->senha_ftp,
                    'link_site_adm' => $site->link_site_adm,
                    'usuario_site_adm' => $site->usuario_site_adm,
                    'senha_site_adm' => $site->senha_site_adm,
                ];
            });

            return response()->json($listar);
        } catch (\Exception $e) {
            return response()->json(['mensagem' => 'Erro ao processar a requisição na função index.', 'erros' => $e->getMessage(), 'info' => ''], 500);
        }
    }

    /**
     * Show the form for creating a new resource.
     */
    public function create(Request $request)
    {
        $validator = Validator::make(
            $request->all(),
            [
                'cliente_id' => 'required',
                'dominio' => 'required',
                'hospedagem' => 'required'
            ],
            [
                'required' => 'O campo :attribute é obrigatório.'
            ]
        );

        if ($validator->fails()) {
            return response()->json(['mensagem' => 'Parâmetros inválidos na requisição.', 'erros' => $validator->errors()->all(), 'info' => ''], 422);
        }

        try {
            Site::create([
                'cliente_id' => $request->cliente_id,
                'resumo' => $request->resumo,
                'dominio' => $request->dominio,
                'acesso_email' => $request->acesso_email,
                'email_profissional' => $request->email_profissional,
                'drive' => $request->drive,
                'youtube' => $request->youtube,
                'facebook' => $request->facebook,
                'linkedin' => $request->linkedin,
                'gmail' => $request->gmail,
                'instagram' => $request->instagram,
                'linktree' => $request->linktree,
                'info_adicionais' => $request->info_adicionais,
                'registro_dominio' => $request->registro_dominio,
                'vencimento_dominio' => $request->vencimento_dominio,
                'usuario_dominio' => $request->usuario_dominio,
                'senha_dominio' => $request->senha_dominio,
                'hospedagem' => $request->hospedagem,
                'vencimento_hospedagem' => $request->vencimento_hospedagem,
                'usuario_hospedagem' => $request->usuario_hospedagem,
                'senha_hospedagem' => $request->senha_hospedagem,
                'dns_primario' => $request->dns_primario,
                'dns_secundario' => $request->dns_secundario,
                'ftp' => $request->ftp,
                'usuario_ftp' => $request->usuario_ftp,
                'senha_ftp' => $request->senha_ftp,
                'link_site_adm' => $request->link_site_adm,
                'usuario_site_adm' => $request->usuario_site_adm,
                'senha_site_adm' => $request->senha_site_adm,
            ]);

            return response()->json(['mensagem' => 'Registro criado com sucesso.', 'erros' => '', 'info' => ''], 200);
        } catch (\Exception $e) {
            return response()->json(['mensagem' => 'Erro ao processar a requisição.', 'erros' => $e->getMessage(), 'info' => ''], 500);
        }
    }

    /**
     * Store a newly created resource in storage. 
     */
    public function store(Request $request)
    {
        //
    }

    /**
     * Display the specified resource.
     */
    public function show()
    {
        return view('cadastrar-site');
    }

    /**
     * Show the form for editing the specified resource.
     */
    public function edit(Site $site)
    {
        //
    }

    /**
     * Update the specified resource in storage.
     */
    public function update(Request $request)
    {
        $validator = Validator::make(
            $request->all(),
            [
                'site_id' => 'required'
            ],
            [
                'required' => 'O campo :attribute é obrigatório.'
            ]
        );

        if ($validator->fails()) {
            return response()->json(['mensagem' => 'Parâmetros inválidos na requisição.', 'erros' => $validator->errors()->all(), 'info' => ''], 422);
        }

        Site::where('id', $request->site_id)->update([
            'dominio' => $request->dominio,
            'acesso_email' => $request->acesso_email,
            'email_profissional' => $request->email_profissional,
            'drive' => $request->drive,
            'youtube' => $request->youtube,
            'facebook' => $request->facebook,
            'linkedin' => $request->linkedin,
            'gmail' => $request->gmail,
            'instagram' => $request->instagram,
            'linktree' => $request->linktree,
            'info_adicionais' => $request->info_adicionais,
            'registro_dominio' => $request->registro_dominio,
            'vencimento_dominio' => $request->vencimento_dominio,
            'usuario_dominio' => $request->usuario_dominio,
            'senha_dominio' => $request->senha_dominio,
            'hospedagem' => $request->hospedagem,
            'vencimento_hospedagem' => $request->vencimento_hospedagem,
            'usuario_hospedagem' => $request->usuario_hospedagem,
            'senha_hospedagem' => $request->senha_hospedagem,
            'dns_primario' => $request->dns_primario,
            'dns_secundario' => $request->dns_secundario,
            'ftp' => $request->ftp,
            'usuario_ftp' => $request->usuario_ftp,
            'senha_ftp' => $request->senha_ftp,
            'link_site_adm' => $request->link_site_adm,
            'usuario_site_adm' => $request->usuario_site_adm,
            'senha_site_adm' => $request->senha_site_adm,
        ]);

        return response()->json(['mensagem' => 'Site atualizado com sucesso!', 'erros' => '', 'info' => '']);
    }

    /**
     * Remove the specified resource from storage.
     */
    public function destroy(Request $request)
    {
        $validator = Validator::make(
            $request->all(),
            [
                'site_id' => 'required'
            ],
            [
                'required' => 'O campo :attribute é obrigatório.'
            ]
        );

        if ($validator->fails()) {
            return response()->json(['mensagem' => 'Parâmetros inválidos na requisição.', 'erros' => $validator->errors()->all(), 'info' => ''], 422);
        }

        $excluir = Site::where('id', $request->site_id)->delete();

        if ($excluir == 0) {
            return response()->json(['mensagem' => 'Id do site não encontrado.', 'erros' => '', 'info' => '']);
        }

        return response()->json(['mensagem' => 'Site excluido com sucesso!', 'erros' => '', 'info' => '']);
    }
}
