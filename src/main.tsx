import { StrictMode } from 'react'
import { createRoot } from 'react-dom/client'
import { Menu } from './components/Menu/index.tsx'
import { Footer } from './components/Footer/Footer.tsx'
import Home from './pages/Home/index.tsx'

import './styles/theme.css'
import './styles/global.css'
import 'bootstrap/dist/css/bootstrap.min.css'
import 'bootstrap/dist/js/bootstrap.bundle.min.js'

createRoot(document.getElementById('root')!).render(
  <StrictMode>
    <Menu />
    <Home />
    <Footer />
  </StrictMode>,
)
