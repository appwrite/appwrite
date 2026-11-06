import { Controller, Get, Patch } from '@nestjs/common'
import { Project } from 'node-appwrite'

@Controller('v1/policies')
export class AppController {
  constructor(private readonly project: Project) {}

  @Patch()
  updatePolicy() {
    return this.project.updatePasswordStrengthPolicy({
      min: 8,
      uppercase: true,
      number: true,
      symbols: true
    })
  }

  @Get()
  listPolicies() {
    return this.project.listPolicies()
  }
}
