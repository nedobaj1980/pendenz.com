<?php
if (!defined('APP_URL_BASE')) require_once __DIR__ . '/../config.php';

$EMAIL_TEMPLATE_INVITE = <<<HTML
<div style="font-family:Arial,sans-serif;max-width:640px;margin:auto">
  <div style="padding:16px;border:1px solid #eee;border-radius:10px">
    <img src="{{brand_logo}}" alt="Logo" style="height:36px;margin-bottom:12px">
    <h2 style="margin:8px 0 4px;">Hallo {{display_name}}</h2>
    <p>Sie wurden eingeladen, Pendenzen digital zu empfangen und zu bearbeiten.</p>
    <p><a href="{{accept_url}}" style="display:inline-block;padding:12px 18px;border-radius:8px;background:#0b5cff;color:#fff;text-decoration:none;font-weight:bold">Konto jetzt einrichten</a></p>
    <p style="color:#555">Der Link ist gültig bis <strong>{{expires_at}}</strong>.</p>
    <p style="font-size:12px;color:#888">Falls der Button nicht funktioniert, öffnen Sie diesen Link:<br>{{accept_url}}</p>
    <img src="{{open_pixel}}" width="1" height="1" style="display:block;border:0" alt="">
    <hr style="border:none;border-top:1px solid #eee;margin:16px 0">
    <p style="font-size:12px;color:#888">Helvetic Immo – pendenz.com</p>
  </div>
</div>
HTML;
