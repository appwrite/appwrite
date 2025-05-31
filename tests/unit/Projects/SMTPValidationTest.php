<?php
namespace Tests\Unit\Projects;
use PHPUnit\Framework\TestCase;
use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;

class SMTPValidationTest extends TestCase
{
    public function testInvalidSMTPCredentials()
    {
        $mail = new PHPMailer(true);
        $mail->isSMTP();
        $mail->SMTPAuth = true;
        $mail->Username = 'fakeuser@gmail.com';
        $mail->Password = 'wrongpassword';
        $mail->Host = 'smtp.gmail.com';
        $mail->Port = 587;
        $mail->SMTPSecure = 'tls';
        $mail->SMTPAutoTLS = false;
        $mail->Timeout = 5;
        $this->expectException(Exception::class);
        $this->expectExceptionMessageMatches('/Could not authenticate/i');
        $mail->SmtpConnect();
    }
}
