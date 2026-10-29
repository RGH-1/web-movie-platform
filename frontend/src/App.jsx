import { useState } from 'react'
import heroImg from './assets/hero.png'
import reactLogo from './assets/react.svg'
import viteLogo from './assets/vite.svg'
import ApiHealthCheck from './components/ApiHealthCheck';
import './App.css'

function App() {
  const [count, setCount] = useState(0)

  return (
    <div className="container py-5">
      <h1>Movie Platform</h1>
      <p>Development Environment</p>

      <ApiHealthCheck />
    </div>
  )
}

export default App
