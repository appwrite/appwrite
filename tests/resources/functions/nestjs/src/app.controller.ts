import { Body, Controller, Get, Header, Headers, HttpCode, Post, Req } from '@nestjs/common';

@Controller()
export class AppController {
  @Get()
  root(@Req() req: { method: string; path: string }) {
    return { framework: 'nestjs', method: req.method, path: req.path };
  }

  // Events and schedules arrive here, told apart by x-appwrite-trigger.
  @Post()
  @HttpCode(200)
  trigger(
    @Headers('x-appwrite-trigger') trigger: string,
    @Headers('x-appwrite-event') event: string,
    @Body() body: { $id?: string },
  ) {
    console.log(`trigger=${trigger} event=${event} id=${body?.$id ?? ''}`);
    return '';
  }

  @Post('echo')
  @HttpCode(201)
  @Header('x-echo', 'yes')
  echo(@Body() body: unknown, @Headers('x-test') header?: string) {
    return { body, header: header ?? null };
  }

  @Get('env')
  env() {
    console.log('nestjs log line');
    return { functionId: process.env.APPWRITE_FUNCTION_ID ?? null, port: process.env.PORT ?? null };
  }
}
