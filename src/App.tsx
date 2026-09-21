import 'bootstrap/dist/css/bootstrap.min.css'
import 'bootstrap/dist/js/bootstrap.bundle.min.js'
import './styles/global.css'
import './styles/theme.css'
import { Menu } from './components/Menu'

function App() {

  return (
    <>
    <Menu />
      <p>
        Ola, este sistema foi feito para guardar suas credenciais de acesso,
        tanto de seus clientes como suas.
      </p>
    </>
  )
}

export default App