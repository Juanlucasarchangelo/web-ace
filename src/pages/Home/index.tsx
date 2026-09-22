import { FullScream } from "../../components/FullScream/FullScream"

function Home() {

    return (
        <>
            <h1 className='pt-5 pb-3'>
                ACE - All Content Encripted
            </h1>
            <p>
                Ola, este sistema foi feito para guardar suas credenciais de acesso,
                tanto de seus clientes como suas.
            </p>
            <div className='justify-content-between d-flex col-12 pt-4 pb-5'>
                <div className='row-4 p-3'>
                    <div className="card">
                        <div className="card-body">
                            <p className="card-text">Guardar todas as inforamações de clientes em um só lugar, mais fácil e pratico.</p>
                        </div>
                    </div>
                </div>
                <div className='row-4 p-3'>
                    <div className="card">
                        <div className="card-body">
                            <p className="card-text">Mensalidade que cabe no bolso do empreendedor Brasileiro, planos a partir de 39,90.</p>
                        </div>
                    </div>
                </div>
                <div className='row-4 p-3'>
                    <div className="card">
                        <div className="card-body">
                            <p className="card-text">Faça seu teste gratis de 1 mês e descubra as facilidades do ACE, cancele quando quiser.</p>
                        </div>
                    </div>
                </div>
            </div>
            <div className="bg-secondary p-4">
                <div className="d-flex justify-content-between col-12">
                    <div className="row-8 p-5 text-start text-light">
                        <h1>Conheça nossa ferramenta de perto!</h1>
                        <p>Lorem ipsum dolor sit amet, consectetur adipisicing elit. Repellat, nulla cum ex exercitationem odio a aliquam necessitatibus numquam laboriosam illo dolore natus, laudantium rem non assumenda corporis quas, consequuntur qui?</p>
                    </div>
                    <div className="row-4">
                        <div className="card text-center">
                            <div className="card-header bg-success">
                                1 MÊS GRATIS
                            </div>
                            <div className="card-body">
                                <h5 className="card-title">Primeiro mês GRATIS</h5>
                                <p className="card-text">O primeiro mês do uso da nossa ferramenta é completamente gratuito.</p>
                                <a href="#" className="btn btn-success">Teste Grátis</a>
                            </div>
                            <div className="card-footer text-body-secondary">
                                PROMOÇÂO
                            </div>
                        </div>
                    </div>
                </div>
            </div>
            <div className="pt-5 pb-5">
                <h1>Entre em contato</h1>
                <div className="card m-5">
                    <div className="carb-body m-4 text-start">
                        <div className="mb-3">
                            <label className="form-label">Email address</label>
                            <input type="email" className="form-control" id="exampleFormControlInput1" placeholder="name@example.com" />
                        </div>
                        <div className="mb-3">
                            <label className="form-label">Example textarea</label>
                            <textarea className="form-control" id="exampleFormControlTextarea1" ></textarea>
                        </div>
                    </div>
                    <div className="card-footer d-flex justify-content-end gap-3">
                        <button className="btn btn-outline-secondary">Deletar</button>
                        <button className="btn btn-outline-success">Enviar</button>
                    </div>
                </div>
            </div>
        </>
    )
}

export default Home