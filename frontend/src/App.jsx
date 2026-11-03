import { useState } from 'react'
import ApiHealthCheck from './components/ApiHealthCheck';
import './App.css'
import TmdbIntegrationTest from './components/TmdbIntegrationTest';

function App() {
  const [count, setCount] = useState(0)

  return (
    <div className="container py-5">
      <h1>Movie Platform</h1>
      <p>Development Environment</p>

      <ApiHealthCheck />
        <TmdbIntegrationTest />
    </div>
  )
}

export default App
