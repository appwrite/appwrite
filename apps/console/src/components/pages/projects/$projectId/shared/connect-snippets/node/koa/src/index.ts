import Koa from 'koa'
import Router from '@koa/router'
import { client } from '../lib/appwrite'
import { Project } from 'node-appwrite'

const app = new Koa()
const router = new Router()
const project = new Project(client)

router.patch('/v1/policies', async (ctx) => {
  ctx.body = await project.updatePasswordStrengthPolicy({
    min: 8,
    uppercase: true,
    number: true,
    symbols: true
  })
})

router.get('/v1/policies', async (ctx) => {
  ctx.body = await project.listPolicies()
})

app.use(router.routes())
app.listen(3000, () => console.log('Listening on http://localhost:3000'))
