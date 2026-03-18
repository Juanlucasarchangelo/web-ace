<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

class UserRegister extends Controller
{
    /**
     * Display a listing of the resource.
     */
    public function index()
    {
        try {
            $clientes = User::with('site')
                ->get()
                ->makeHidden(['deleted_at', 'created_at', 'updated_at']);

            $listar = $clientes->map(function ($cliente) {
                return [
                    'id' => $cliente->id,
                    'nome' => $cliente->nome,
                    'sobrenome' => $cliente->sobrenome,
                    'email' => $cliente->email,
                    'cpf_cnpj' => $cliente->cpf_cnpj,
                    'telefone' => $cliente->telefone,
                    'endereco' => $cliente->endereco,
                    'cidade' => $cliente->cidade,
                    'bairro' =>  $cliente->bairro,
                    'numero' => $cliente->numero,
                    'complemeto' => $cliente->complemento,
                    'cep' => $cliente->cep,
                    'sites' => $cliente->site
                ];
            });

            return response()->json($listar);
        } catch (\Exception $e) {
            return response()->json(['mensagem' => 'Erro ao processar a requisição.', 'erro' => $e->getMessage()], 500);
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
                'nome' => 'required',
                'sobrenome' => 'required',
                'email' => 'required',
            ],
            [
                'required' => 'O campo :attribute é obrigatório.'
            ]
        );

        if ($validator->fails()) {
            return response()->json(['mensagem' => 'Parâmetros inválidos na requisição.', 'erros' => $validator->errors()->all(), 'info' => ''], 422);
        }

        try {
            User::create([
                'nome' => $request->nome,
                'sobrenome' => $request->sobrenome,
                'email' => $request->email,
                'cpf_cnpj' => $request->cpf_cnpj,
                'telefone' => $request->telefone,
                'endereco' => $request->endereco,
                'cidade' => $request->cidade,
                'bairro' => $request->bairro,
                'numero' => $request->numero,
                'complemento' => $request->complemento,
                'cep' => $request->cep,
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
        return view('cliente/listar-cliente');
    }

    /**
     * Show the form for editing the specified resource.
     */
    public function edit()
    {
        //
    }

    /**
     * Update the specified resource in storage.
     */
    public function update()
    {
        //
    }

    /**
     * Remove the specified resource from storage.
     */
    public function destroy()
    {
        //
    }
}
