import { spawn, spawnSync } from 'node:child_process'
import fs from 'node:fs'

import { back, banco, env } from './env.mjs'

const porta = process.env.BACK_PORT || '8100'

fs.rmSync(banco, { force: true })
fs.writeFileSync(banco, '')
const artisan = (...args) => {
  const r = spawnSync('php', ['artisan', ...args], { cwd: back, env, stdio: 'inherit' })
  if (r.status !== 0) { console.error('artisan falhou:', args.join(' ')); process.exit(1) }
}
artisan('migrate:fresh', '--seed', '--force')
artisan('tinker', '--execute', `DB::table("municipio")->insert([
  ["ibge"=>"3550308","nome"=>"São Paulo","nome_busca"=>"SAO PAULO","uf"=>"SP"],
  ["ibge"=>"3556602","nome"=>"Valparaíso","nome_busca"=>"VALPARAISO","uf"=>"SP"],
  ["ibge"=>"3509502","nome"=>"Campinas","nome_busca"=>"CAMPINAS","uf"=>"SP"],
  ["ibge"=>"3536505","nome"=>"Paulínia","nome_busca"=>"PAULINIA","uf"=>"SP"],
]);`)

const servidor = spawn('php', ['artisan', 'serve', '--host=127.0.0.1', `--port=${porta}`, '--no-reload'], { cwd: back, env, stdio: 'inherit' })
const sair = () => { servidor.kill(); process.exit(0) }
process.on('SIGINT', sair)
process.on('SIGTERM', sair)
servidor.on('exit', (c) => process.exit(c ?? 0))
