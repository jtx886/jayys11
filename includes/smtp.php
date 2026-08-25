<?php
/**
 * Jay影视 - SMTP 邮件发送类（纯 Socket 实现，无需扩展）
 * SMTP 固定配置（163 邮箱）
 */

if (!defined('SMTP_HOST')) {
    define('SMTP_HOST', 'smtp.163.com');
    define('SMTP_PORT', 465);
    define('SMTP_USER', 'jtxnb886@163.com');
    define('SMTP_PASS', 'FLLRDtadYAfGXp9Y');
    define('SMTP_FROM', 'jtxnb886@163.com');
    define('SMTP_FROM_NAME', 'Jay影视');
}

class JayMailer
{
    private $socket = null;
    private $error  = '';

    public function send($toEmail, $toName, $subject, $htmlBody) {
        $this->error = '';
        if (!filter_var($toEmail, FILTER_VALIDATE_EMAIL)) {
            $this->error = '收件邮箱格式错误';
            return false;
        }
        try {
            $this->connect();
            $this->command("EHLO jaymovie.local");
            $this->auth();
            $this->command("MAIL FROM:<" . SMTP_FROM . ">");
            $toName = $toName !== '' ? $this->encodeHeader($toName) : '';
            $this->command("RCPT TO:<{$toEmail}>");
            $this->command("DATA");

            $headers = "";
            $from    = $this->encodeHeader(SMTP_FROM_NAME) . " <" . SMTP_FROM . ">";
            $headers .= "From: {$from}\r\n";
            $headers .= "To: " . ($toName !== '' ? $toName . " " : "") . "<{$toEmail}>\r\n";
            $headers .= "Subject: " . $this->encodeHeader($subject) . "\r\n";
            $headers .= "Date: " . date('r') . "\r\n";
            $headers .= "MIME-Version: 1.0\r\n";
            $headers .= "Content-Type: text/html; charset=UTF-8\r\n";
            $headers .= "Content-Transfer-Encoding: base64\r\n";
            $headers .= "X-Mailer: JayMovie Mailer\r\n";

            $body  = chunk_split(base64_encode($htmlBody));
            $mail  = $headers . "\r\n" . $body . "\r\n.";
            $this->command($mail, 250);

            $this->command("QUIT");
            $this->close();
            return true;
        } catch (Exception $ex) {
            $this->error = $ex->getMessage();
            $this->close();
            return false;
        }
    }

    public function error() {
        return $this->error;
    }

    private function connect() {
        $target  = (SMTP_PORT == 465) ? 'ssl://' . SMTP_HOST : SMTP_HOST;
        $context = stream_context_create(['ssl' => ['verify_peer' => false, 'verify_peer_name' => false, 'allow_self_signed' => true]]);
        $this->socket = @stream_socket_client("{$target}:" . SMTP_PORT, $errno, $errstr, 15, STREAM_CLIENT_CONNECT, $context);
        if (!$this->socket) {
            throw new Exception("SMTP 连接失败: {$errstr} ({$errno}) —— 免费空间若封锁SMTP端口会出现此错误");
        }
        stream_set_timeout($this->socket, 15);
        $this->readResponse(220);
    }

    private function auth() {
        // 尝试 CRAM-MD5 / LOGIN / PLAIN
        try {
            $resp = $this->rawCommand("AUTH LOGIN");
            $this->rawCommand(base64_encode(SMTP_USER));
            $this->rawCommand(base64_encode(SMTP_PASS), 235);
            return;
        } catch (Exception $e) {
            throw new Exception('SMTP 认证失败，请检查账号密码');
        }
    }

    private function command($cmd, $expectCode = 250) {
        fwrite($this->socket, $cmd . "\r\n");
        $this->readResponse($expectCode);
    }

    private function rawCommand($cmd, $expectCode = 334) {
        fwrite($this->socket, $cmd . "\r\n");
        return $this->readResponse($expectCode);
    }

    private function readResponse($expectCode) {
        $code = 0;
        $data = '';
        while (($line = fgets($this->socket, 515)) !== false) {
            $data .= $line;
            if (isset($line[3]) && $line[3] === ' ') {
                $code = (int)substr($line, 0, 3);
                break;
            }
            if (trim($line) === '') break;
        }
        $expected = (int)$expectCode;
        if ($code === 0) throw new Exception('SMTP 无响应');
        // 4xx/5xx 统一视为失败
        if ($code >= 500 || ($code >= 400 && $code !== $expected)) {
            throw new Exception("SMTP 返回错误 [{$code}]: " . trim($data));
        }
        return $data;
    }

    private function close() {
        if ($this->socket) { @fclose($this->socket); $this->socket = null; }
    }

    private function encodeHeader($str) {
        return '=?UTF-8?B?' . base64_encode($str) . '?=';
    }
}

/**
 * 邮件外层模板（精致暗色渐变 HTML 邮件）
 */
function mail_wrap($title, $innerHtml, $footer = 'Jay影视 · 让观影更简单') {
    $title = e($title);
    return <<<HTML
<!DOCTYPE html>
<html>
<head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"></head>
<body style="margin:0;padding:0;background:#0d1017;">
<div style="display:none;max-height:0;overflow:hidden;">{$title}</div>
<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background:#0d1017;padding:32px 12px;font-family:'PingFang SC','Microsoft YaHei',Helvetica,Arial,sans-serif;">
<tr><td align="center">
<table role="presentation" width="600" cellpadding="0" cellspacing="0" style="max-width:600px;width:100%;background:#151a24;border-radius:16px;overflow:hidden;border:1px solid #232a3a;">
  <!-- 顶部渐变条 -->
  <tr><td style="height:6px;background:linear-gradient(90deg,#e50914,#ff5a3c,#ffb199);font-size:0;line-height:0;">&nbsp;</td></tr>
  <!-- Logo 区 -->
  <tr><td style="padding:36px 40px 10px;text-align:center;">
    <div style="display:inline-block;width:52px;height:52px;line-height:52px;background:linear-gradient(135deg,#e50914,#ff5a3c);border-radius:14px;color:#fff;font-size:24px;font-weight:bold;text-align:center;box-shadow:0 8px 24px rgba(229,9,20,.35);">&#9654;</div>
    <div style="margin-top:14px;font-size:20px;font-weight:bold;color:#ffffff;letter-spacing:2px;">Jay<span style="color:#ff5a3c;">影视</span></div>
  </td></tr>
  <!-- 标题 -->
  <tr><td style="padding:10px 40px 0;text-align:center;">
    <div style="font-size:18px;font-weight:bold;color:#e8eaf0;">{$title}</div>
  </td></tr>
  <!-- 内容 -->
  <tr><td style="padding:24px 40px 8px;">
    <div style="background:#10141d;border:1px solid #232a3a;border-radius:12px;padding:26px 26px;color:#c5cbdb;font-size:14px;line-height:1.9;">
      {$innerHtml}
    </div>
  </td></tr>
  <!-- 底部 -->
  <tr><td style="padding:18px 40px 34px;text-align:center;">
    <div style="color:#5c657a;font-size:12px;line-height:1.8;">{$footer}<br>此邮件由系统自动发送，请勿直接回复</div>
  </td></tr>
</table>
</td></tr></table>
</body></html>
HTML;
}

/**
 * 验证码邮件
 */
function mail_template_code($code) {
    $inner = <<<HTML
<div style="text-align:center;padding:4px 0;">
  <div style="color:#8b93a7;font-size:13px;margin-bottom:16px;">您正在进行账号注册，验证码为</div>
  <div style="font-size:40px;font-weight:bold;letter-spacing:12px;color:#ffffff;background:linear-gradient(135deg,#e50914,#ff5a3c);background-clip:padding-box;border-radius:12px;padding:18px 10px;margin:0 auto 18px;width:280px;max-width:100%;box-shadow:0 10px 30px rgba(229,9,20,.3);">{$code}</div>
  <div style="color:#8b93a7;font-size:13px;">验证码 <b style="color:#ff8a70;">10 分钟</b> 内有效，请勿泄露给他人</div>
  <div style="color:#5c657a;font-size:12px;margin-top:14px;">如非本人操作，请忽略此邮件</div>
</div>
HTML;
    return mail_wrap('邮箱验证码', $inner);
}

/**
 * 封禁通知邮件
 */
function mail_template_ban($username, $reason, $startTime, $endTime) {
    $row = function($label, $value) {
        $value = e($value);
        return <<<HTML
        <tr>
          <td style="padding:10px 14px;color:#8b93a7;font-size:13px;white-space:nowrap;border-bottom:1px solid #232a3a;">{$label}</td>
          <td style="padding:10px 14px;color:#e8eaf0;font-size:13px;border-bottom:1px solid #232a3a;">{$value}</td>
        </tr>
HTML;
    };
    $inner = '<div style="margin-bottom:6px;">尊敬的用户 <b style="color:#ff8a70;">' . e($username) . '</b>，您好：</div>'
           . '<div style="margin-bottom:16px;">您的账号因以下原因被封禁，封禁期间将无法登录与观看，请您知悉：</div>'
           . '<table cellpadding="0" cellspacing="0" width="100%" style="border:1px solid #232a3a;border-radius:10px;overflow:hidden;">'
           . $row('封禁原因', $reason)
           . $row('封禁开始时间', $startTime)
           . $row('封禁解除时间', $endTime)
           . '</table>'
           . '<div style="margin-top:16px;color:#8b93a7;font-size:12px;">到达解除时间后将自动解封，如有疑问请联系管理员。</div>';
    return mail_wrap('账号封禁通知', $inner);
}

/**
 * 管理员自定义邮件（邮件推送）
 */
function mail_template_custom($username, $contentHtml) {
    $inner = '<div style="margin-bottom:6px;">尊敬的用户 <b style="color:#ff8a70;">' . e($username) . '</b>，您好：</div>'
           . '<div style="color:#c5cbdb;">' . $contentHtml . '</div>';
    return mail_wrap('来自Jay影视的通知', $inner);
}

/**
 * 发送邮件的快捷方法，返回 [bool, error]
 */
function send_mail($toEmail, $toName, $subject, $html) {
    $mailer = new JayMailer();
    $ok = $mailer->send($toEmail, $toName, $subject, $html);
    return [$ok, $mailer->error()];
}
