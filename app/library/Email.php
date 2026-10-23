<?php

namespace Library;
use Phalcon\Di\Injectable;
use Swift_Mailer as Mailer;
use Swift_Message as Message;
use Swift_SmtpTransport as Smtp;

class Email extends Injectable
{
  // Custom SMTP (Global Settings > Email notification settings > SMTP). Swift Mailer never
  // upgrades to TLS on its own, so servers that need STARTTLS before login (Gmail, Office 365
  // on 587) refused every send. Port 465 = implicit TLS, 25 = plain, any other port = STARTTLS.
  // The host field also accepts "https://host" or "host:port", so strip those.
  private function customSmtpTransport($smtpSettings)
  {
    $host = preg_replace('#^[a-z][a-z0-9+.-]*://#i', '', trim((string) ($smtpSettings["smtpHost"] ?? "")));
    $host = preg_replace('#[/:].*$#', '', $host);
    $port = intval($smtpSettings["smtpPort"] ?? 0) ?: 25;
    $encryption = $port == 465 ? "ssl" : ($port == 25 ? null : "tls");
    $transport = new Smtp($host, $port, $encryption);
    $transport->setUsername($smtpSettings["smtpUsername"] ?? "");
    $transport->setPassword($smtpSettings["smtpPassword"] ?? "");
    $transport->setTimeout(20);
    return $transport;
  }

  public function send($to, $subject, $name, $params, $licenseConfig, $customName = "RingQ")
  {
    $fqdn = $licenseConfig['data']['fqdn'];
    $enabled = $this->redis->get("smtp:" . $fqdn . ":enabled");
    $customSMTP = $enabled == "1";
    $mailer = "";
    $message = "";
    $attachment = "";
    $banner = $this->redis->get($fqdn . "-banner");
    $footer = $this->redis->get($fqdn . "-footer");
        
    if ($customName === null || $customName === '') {
      $customName = "RingQ";
    }

    if ($customSMTP) {
      if (isset($banner)) {
        $banner = $fqdn .':8443/img/'. $banner; 
      }
      if (isset($footer)) {
        $footer = $fqdn .':8443/img/'. $footer; 
      }
      $smtpSettings = $this->redis->hgetall("smtp:" . $fqdn . ":settings");
      $transport = $this->customSmtpTransport($smtpSettings);
      $mailer = new Mailer($transport);
      $message = new Message($subject);

      $fromEmail = $smtpSettings["smtpEmail"];
      $fromName  = $smtpSettings["smtpName"];
      $customName = $fromName;
      // $message->setFrom([$fromEmail => $fromName]);
      $message->setFrom([$fromEmail => $fromName]);
      $message->setTo($to);
    } else {
      //Default
      $fromEmail = "noreply@ringq.com";
      $fromName  = $customName;
      $banner = null;
      $footer = null;
    }

    if ($fromEmail === null || $fromEmail === ''){
      $fromEmail = "noreply@ringq.com";
      $fromName = "RingQ";
    }

    $emailData = [];
    $emailData['emailData']['personalizations'] = [
      [
        "to" => [
          [
            "email" => $to,
          ]
        ],
        "subject" => $subject
      ]
    ];

    $emailData['emailData']['from'] = [
      "email" => $fromEmail,
      "name"  => "test"
  ];

    $body = "";
    if ($params["type"] == 1) {
      if (isset($banner)) {
        $headerOpen = '<td style="padding:20px; display:block; height:70px; background:url(\'https://' . $banner . ' \') center/cover no-repeat;">'; 
        $headerContent = ''; // Hide logo/text if a custom banner is used
      } else {
        $headerOpen = '<td style="padding:20px; display:block; height:auto; background:url(\'https://cap.ringq.com/emailuse/WhatsApp-Image-2025-09-24-at-09.47.46.jpeg\') center/cover no-repeat;">'; 
        $headerContent = '
          <table width="100%" cellpadding="0" cellspacing="0" border="0">
              <tbody><tr>
                <td align="left">
                  <img src="https://cap.ringq.com/emailuse/RingQlogomailer-2.png" alt="RingQ Logo" width="130" height="65" style="display:block; max-width:130px; height:65px;">
                </td>
              </tr></tbody>
          </table>';
      }

      // --- Footer Logic ---
      $footerTag = '
          <td bgcolor="#11224D" valign="middle" style="text-align:center; padding:15px;">
                        <table align="center" border="0" cellpadding="0" cellspacing="0" width="100%">
                          <tr>
                            <td style="text-align:left; line-height:20px; padding:10px 30px; color:#8c94c4; font-weight:lighter; font-family: Arial, Helvetica, sans-serif; font-size:12px;">
                              This email was sent to 
                                <a href="mailto:'.$to.'" style="text-decoration: underline; color:rgb(0, 159, 227) !important;">
                                        <span style="color:rgb(0, 159, 227) !important;">'.$to.'</span>
                                    </a>. 
                              Update your mailing preferences from your 
                              <span style="color:#216798;">Customer Portal</span> profile.
                            </td>
                          </tr>
    
                          <tr>
                            <td style="padding:10px 30px;">
                              <table width="100%" cellpadding="0" cellspacing="0" border="0">
                                <tr>
                                  <td align="left">
                                    <img src="https://cap.ringq.com/emailuse/RingQlogomailerfooter-2.png"
                                        alt="RingQ Logo"
                                        style="display:block; width:56.5px; height:27.01px;">
                                  </td>
                                  <td align="right">
                                    <a href="https://www.facebook.com/ringqcallcenter" style="margin-right:10px;">
                                      <img src="https://cap.ringq.com/emailuse/facebook-2.png" alt="Facebook" width="20" height="20">
                                    </a>
                                    <a href="https://www.linkedin.com/company/ringqcallcenter/" style="margin-right:10px;">
                                      <img src="https://cap.ringq.com/emailuse/linkedin-2.png" alt="LinkedIn" width="20" height="20">
                                    </a>
                                    <a href="https://www.youtube.com/@RingQ-ry6kw" style="margin-right:10px;">
                                      <img src="https://cap.ringq.com/emailuse/youtube-1.png" alt="YouTube" width="20" height="20">
                                    </a>
                                    <a href="https://x.com/RingQcloudcx" style="margin-right:10px;">
                                      <img src="https://cap.ringq.com/emailuse/twitter.png" alt="Twitter" width="20" height="20">
                                    </a>
                                    <a href="https://www.instagram.com/ringqcloudcx">
                                      <img src="https://cap.ringq.com/emailuse/instagram-1.png" alt="Instagram" width="20" height="20">
                                    </a>
                                  </td>
                                </tr>
                              </table>
                            </td>
                          </tr>
    
                          <tr>
                            <td align="center" style="color:#216798; font-size:10px; font-weight:lighter; font-family: Arial, Helvetica, sans-serif; padding-top:10px;">
                              Copyright ©️ 2026 '. $customName .' . All Rights Reserved.
                            </td>
                          </tr>
                        </table>
                      </td>'; 

      if (isset($footer)) {
        $footerTag = '<td style="padding:20px; display:block; height:70px; background:url(\'https://' . $footer . ' \') center/cover no-repeat;"></td>'; 
      }

      $body = '<head>
              <meta charset="UTF-8"><meta name="viewport" content="width=device-width,initial-scale=1">
                <meta http-equiv="X-UA-Compatible" content="IE=edge">
                <title>Your ' . $customName . '  Verification Code</title>
                <style type="text/css">
                body,
                html{
                    margin:0!important;padding:0!important;height:100%!important;width:100%!important
                }
                *{-ms-text-size-adjust:100%;-webkit-text-size-adjust:100%}.ExternalClass{width:100%}div[style*="margin: 16px 0"]{margin:0!important}table,td{mso-table-lspace:0!important;mso-table-rspace:0!important}table{border-spacing:0!important;border-collapse:collapse!important;table-layout:fixed!important;margin:0 auto!important}table table table{table-layout:auto}img{-ms-interpolation-mode:bicubic}.yshortcuts a{border-bottom:none!important}a[x-apple-data-detectors]{color:inherit!important}a:link{text-decoration:none;color: rgb(0, 159, 227);a:visited{color:#87ceeb}
                </style>
                <style type="text/css">.button-a,.button-td{transition:all .1s ease-in}.button-a:hover,.button-td:hover{background:#0089cb!important;border-color:#0089cb!important}
                @media screen and (max-width:600px){.email-container{width:100%!important}.fluid,.fluid-centered{max-width:100%!important;height:auto!important;margin-left:auto!important;margin-right:auto!important}.fluid-centered{margin-left:auto!important;margin-right:auto!important}.stack-column,.stack-column-center{display:block!important;width:100%!important;max-width:100%!important;direction:ltr!important}.stack-column-center{text-align:center!important}.center-on-narrow{text-align:center!important;display:block!important;margin-left:auto!important;margin-right:auto!important;float:none!important}table.center-on-narrow{display:inline-block!important}}</style>
                <link href="https://cdn.usebootstrap.com/bootstrap/4.3.1/css/bootstrap.min.css" rel="stylesheet" type="text/css">
            </head>
        <body bgcolor="#e0e0e0" width="100%" style="margin: 0; padding:0;" yahoo="yahoo">
        <table bgcolor="#e0e0e0" cellpadding="0" cellspacing="0" border="0" height="100%" width="100%" style="border-collapse:collapse;">
          <tr>
            <td align="center">
              <table cellspacing="0" cellpadding="0" border="0" align="center" bgcolor="#f3f3f3" width="600" class="email-container" style="border-collapse:collapse;">
                
                <!-- Header -->
              <thead>
                   <tr>
                    ' . $headerOpen . $headerContent . '</td>
                   </tr>
                  </thead>

                <!-- Greeting + Content -->
                <tr>
                  <td style="padding:20px; font-family: Arial, Helvetica, sans-serif; font-size:12px; color:#333333; background:#ffffff;">
                    <p style="font-size:12px; margin:0 0 15px 0;">Welcome to '. $customName .'!,</p>
                    <p style="font-size:12px; margin:0 0 20px 0; line-height:2;">
                      Thanks for signing up with <strong> '. $customName .' !</strong>
                      To complete your registration, please click the button below to activate your account.
                    </p>
                
                    <table role="presentation" border="0" cellspacing="0" cellpadding="0" align="center" style="margin: 25px auto;">
                      <tr>
                        <td align="center" bgcolor="#1f274c" style="border-radius:50px;">
                          <a href="https://' . $params["fqdn"] . "/cloudapp/?verify=" . $params["verification"] . "&email=" . $to . '" 
                            style="display:inline-block; font-size:14px; font-family:Arial, Helvetica, sans-serif; color:#ffffff; text-decoration:none; 
                                  padding:12px 28px; background-color:#11224D; border-radius:20px; font-weight:bold;">
                            Activate Account
                          </a>
                        </td>
                      </tr>
                    </table>

                    <p style="font-size:12px; text-align: center; margin-top: 15px;">https://' . $params["fqdn"]. '/cloudapp</p>
                    <p style="font-size:12px; color:#555; margin-top:20px;">
                      Your email verification code will expire in 15 minutes.
                    </p>
                    <p style="font-size:12px; color:#555; margin:10px 0;">
                      If you did not request a code to sign up for '. $customName .', you can safely ignore this email. An account has not been created.
                    </p>
                  </td>
                </tr>

                  <tfoot>
                 <tr>
                    ' . $footerTag . '
                    </td>
                 </tr>
               </tfoot>


              </table>
            </td>
          </tr>
        </table>
      </body>';      
    } 
    if ($params["type"] == 2) {

              // --- Header Logic ---
              if (isset($banner)) {
                $headerOpen = '<td style="padding:20px; display:block; height:70px; background:url(\'https://' . $banner . ' \') center/cover no-repeat;">'; 
                $headerContent = ''; // Hide logo/text if a custom banner is used
              } else {
                $headerOpen = ' <td style="padding:30px; padding-left:40px; background: url(https://cap.ringq.com/emailuse/WhatsApp-Image-2025-09-24-at-09.47.46.jpeg) center/cover no-repeat, rgba(0,0,0,0.4); color: white;">'; 
                $headerContent = '<table width="100%" cellpadding="0" cellspacing="0" border="0">
                          <tr>
                            <td align="left">
                              <img src="https://cap.ringq.com/emailuse/RingQlogomailer-2.png"
                                  alt="RingQ Logo"
                                  width="130"
                                  height="65"
                                  style="display:block; max-width:130px; height:65px;">
                            </td>
                            <td align="right" style="font-family: Arial, Helvetica, sans-serif; font-size:16px; font-weight:bold; color:#FAFAFA;">
                              Welcome to '. $customName .' !
                            </td>
                          </tr>
                        </table>
                     ';
              }
      
              // --- Footer Logic ---
              $footerTag = '
                  <td bgcolor="#11224D" valign="middle" style="text-align:center; padding:15px;">
                                <table align="center" border="0" cellpadding="0" cellspacing="0" width="100%">
                                  <tr>
                                    <td style="text-align:left; line-height:20px; padding:10px 30px; color:#8c94c4; font-weight:lighter; font-family: Arial, Helvetica, sans-serif; font-size:12px;">
                                      This email was sent to 
                                     <a href="mailto:'.$to.'" style="text-decoration: underline; color:rgb(0, 159, 227) !important;">
                                        <span style="color:rgb(0, 159, 227) !important;">'.$to.'</span>
                                    </a>.
                                      Update your mailing preferences from your 
                                      <span style="color:#216798;">Customer Portal</span> profile.
                                    </td>
                                  </tr>
            
                                  <tr>
                                    <td style="padding:10px 30px;">
                                      <table width="100%" cellpadding="0" cellspacing="0" border="0">
                                        <tr>
                                          <td align="left">
                                            <img src="https://cap.ringq.com/emailuse/RingQlogomailerfooter-2.png"
                                                alt="RingQ Logo"
                                                style="display:block; width:56.5px; height:27.01px;">
                                          </td>
                                          <td align="right">
                                            <a href="https://www.facebook.com/ringqcallcenter" style="margin-right:10px;">
                                              <img src="https://cap.ringq.com/emailuse/facebook-2.png" alt="Facebook" width="20" height="20">
                                            </a>
                                            <a href="https://www.linkedin.com/company/ringqcallcenter/" style="margin-right:10px;">
                                              <img src="https://cap.ringq.com/emailuse/linkedin-2.png" alt="LinkedIn" width="20" height="20">
                                            </a>
                                            <a href="https://www.youtube.com/@RingQ-ry6kw" style="margin-right:10px;">
                                              <img src="https://cap.ringq.com/emailuse/youtube-1.png" alt="YouTube" width="20" height="20">
                                            </a>
                                            <a href="https://x.com/RingQcloudcx" style="margin-right:10px;">
                                              <img src="https://cap.ringq.com/emailuse/twitter.png" alt="Twitter" width="20" height="20">
                                            </a>
                                            <a href="https://www.instagram.com/ringqcloudcx">
                                              <img src="https://cap.ringq.com/emailuse/instagram-1.png" alt="Instagram" width="20" height="20">
                                            </a>
                                          </td>
                                        </tr>
                                      </table>
                                    </td>
                                  </tr>
            
                                  <tr>
                                    <td align="center" style="color:#216798; font-size:10px; font-weight:lighter; font-family: Arial, Helvetica, sans-serif; padding-top:10px;">
                                      Copyright ©️ 2026 '. $customName .'. All Rights Reserved.
                                    </td>
                                  </tr>
                                </table>
                              </td>'; 
      
              if (isset($footer)) {
                $footerTag = '<td style="padding:20px; display:block; height:70px; background:url(\'https://' . $footer . ' \') center/cover no-repeat;"></td>'; 
              }

      $body =
        '<!DOCTYPE html>
          <html>
          <head>
          <meta charset="UTF-8">
          <!-- utf-8 works for most cases -->
          <meta name="viewport" content="width=device-width, initial-scale=1.0">
          <meta http-equiv="X-UA-Compatible" content="IE=edge">
          <title>'. $customName .' verification email</title>
  
          <link href="Welcome Emails SIP Trunks/css/bootstrap-4.3.1.css" rel="stylesheet" type="text/css">
          </head>
  
          <body bgcolor="#e0e0e0" style="margin:0;padding:0;">
          <table width="100%" bgcolor="#e0e0e0" cellpadding="0" cellspacing="0" border="0" style="border-collapse:collapse;">
            <tr>
              <td align="center">
  
                <!-- Main container -->
                <table width="600" cellpadding="0" cellspacing="0" border="0" bgcolor="#ffffff" style="border-collapse:collapse;">
              
                  <!-- Header -->
                  <tr>
                  ' . $headerOpen . $headerContent . '</td>
                    </tr>
  
                  <!-- Greeting -->
                <tr>
            <td style="padding:30px; padding-left:40px; font-family:Arial,Helvetica,sans-serif; 
                      font-size:14px; line-height:26px; background-color:#ffffff; color:#333333;">
              Hi <strong>' . $params["firstname"] . '</strong>,<br>
              Welcome to <strong> '. $customName .' !</strong><br>
              With '. $customName .' you can setup call flows, update your status, make call transfers,
              arrange online meetings and much more! And the best bit? You can do it from anywhere –
              either on your laptop or smartphone.
            </td>
          </tr>
  
                  <!-- User Account -->
       <tr>
          <td bgcolor="#ffffff " style="padding-bottom: 20px">
                          <table width="95%" cellpadding="0" cellspacing="0" border="0" style="margin: 0px; background-color: #f0f7ff; border-radius: 16px">
                    <tbody><tr>
            <td align="" style="padding: 20px 30px; padding-left: 40px; font-family: Arial, Helvetica, sans-serif; font-size: 14px; line-height: 22px; color: rgb(51, 51, 51); border-radius: 6px; background-color: #f0f7ff">
                        <strong>Your '. $customName .' User Account</strong><br>

                        • <strong>Your extension number is: ' . $params['extension'] . ' </strong> <br>

                        • <strong>'. $customName .' URL:
                        <a href="https://' . $params["subdomain"] . '/cloudapp" style="color:#009FE3;">
                        '  . "https://" . $params["subdomain"] . '/cloudapp
                      </a> </strong> <br>

                        • <strong>Share your '. $customName .' Talk link to receive free calls:
                       <a href="https://' . $params["url"] . '" style="color:#009FE3;">
              '  . "https://" . $params["url"] . '
                    </a> </strong>
                </td>
                  </tr>
                  </tbody>
                </table>
                      </td>
                    </tr>
  
                <tr>
                <td style="padding:25px 30px 10px 30px;
                          font-family:Arial,Helvetica,sans-serif;
                          font-size:16px;font-weight:bold;color:#333333;  background-color: #ffffff;">
                  Manage Calls “On-the-go”
                </td>
                </tr>
        
                <tr>
        <td style="padding:0 30px 12px 30px; background-color: #ffffff;">
          <table width="100%" cellpadding="0" cellspacing="0" border="0"
                style="border-collapse:separate; border: 1px solid #DEEBF8; border-radius:20px; background-color:#fefefe; overflow:hidden;">
            <tr style="height:50px;">
              <td width="35" align="center" valign="middle"
                  style="background-color: #0459A1; background: linear-gradient(to bottom, #0459A1, #009FE3); color:#ffffff; font-weight:bold; font-size:25px; width:35px; height:50px;">
                1
              </td>

              <td valign="middle"
                  style="padding:12px; font-family:Arial,Helvetica,sans-serif; font-size:14px; line-height:20px; color:#333333; font-weight:bold;">
                Download the '. $customName .' App.&nbsp;
                
                <a href="https://play.google.com/store/apps/details?id=com.ringq.app&hl=en&gl=US"
                  style="display:inline-block; vertical-align:middle; margin-left:6px;">
                  <img src="https://' .  $params['subdomain'] . ':8443/img/play-store.png"
                      alt="Google Play" height="24" style="display:block; border:0;">
                </a>

                <a href="https://apps.apple.com/ph/app/ringq/id6468941160"
                  style="display:inline-block; vertical-align:middle; margin-left:6px;">
                  <img src="https://' .  $params['subdomain'] . ':8443/img/app-store.png"
                      alt="App Store" height="24" style="display:block; border:0;">
                </a>
              </td>

              <td width="40" align="center" valign="middle">
                <img src="https://cap.ringq.com/emailuse/qr-code-1-2.png"
                    alt="Icon" height="24" style="display:inline-block; vertical-align:middle; margin-right:10px;">
              </td>
            </tr>
          </table>
        </td>
      </tr>

      <tr>
        <td style="padding:0 30px 12px 30px; background-color: #ffffff;">
          <table width="100%" cellpadding="0" cellspacing="0" border="0" 
                style="border-collapse:separate; border: 1px solid #DEEBF8; border-radius: 20px; background-color: #fefefe; overflow:hidden;">
            <tr style="height:50px;">
              <td width="35" align="center" valign="middle"
                  style="background-color: #0459A1; background: linear-gradient(to bottom, #0459A1, #009FE3); color:#ffffff; font-weight:bold; font-size:25px; width:35px; height:50px;">2</td>
              <td valign="middle"
                  style="padding-left:12px; font-family:Arial,Helvetica,sans-serif; font-size:14px; line-height:20px; color:#333333; font-weight:bold">
                Allow camera access.
              </td>
              <td width="40" align="center" valign="middle">
                <img src="https://cap.ringq.com/emailuse/Group-1000004612.png"
                    alt="Icon" height="24" style="display:inline-block; vertical-align:middle; margin-right:10px;">
              </td>
            </tr>
          </table>
        </td>
      </tr>

      <tr>
        <td style="padding:0 30px 12px 30px; background-color: #ffffff;">
          <table width="100%" cellpadding="0" cellspacing="0" border="0" 
                style="border-collapse:separate; border: 1px solid #DEEBF8; border-radius: 20px; background-color: #fefefe; overflow:hidden;">
            <tr style="height:50px;">
              <td width="35" align="center" valign="middle"
                  style="background-color: #0459A1; background: linear-gradient(to bottom, #0459A1, #009FE3); color:#ffffff; font-weight:bold; font-size:25px; width:35px; height:50px;">3</td>
              <td valign="middle"
                  style="padding-left:12px; font-family:Arial,Helvetica,sans-serif; font-size:14px; line-height:20px; color:#333333; font-weight:normal">
                When prompted <span style="font-weight:bold"> scan the QR code </span> for automatic configuration.
              </td>
              <td width="40" align="center" valign="middle">
                <img src="https://cap.ringq.com/emailuse/qr-code-1-1.png"
                    alt="Icon" height="24" style="display:inline-block; vertical-align:middle; margin-right:10px;">
              </td>
            </tr>
          </table>
        </td>
      </tr>

  
          <tr>
            <td align="center" style="padding:20px; background:#ffffff;">
              <table cellpadding="0" cellspacing="0" border="0">
                <tr>
                  
                  <td style="padding-right:15px;">
                    <a href="https://' . $params["subdomain"] . '/cloudapp">
                      <img src="https://' . $params["subdomain"] . ':8443/temp/' . $params["verification"] . '.png"
                        alt="QR Code"
                        style="width:80px; height:80px; border:2px solid #009FE3; border-radius:8px; display:block;">
                    </a>
                  </td>

                  <td style="font-family:Arial, Helvetica, sans-serif; font-size:14px; color:#555;">
                    Code will expire in 15 minutes.
                  </td>

                </tr>
              </table>
            </td>
          </tr>
  
  
          <tr>
          <td style="padding:0 35px;">
            <table width="100%" cellpadding="0" cellspacing="0" border="0">
              <tr>
                <td style="border-top:2px solid #10a5e5; font-size:0; line-height:0;">&nbsp;</td>
              </tr>
            </table>
          </td>
          </tr>
  
                  <!-- Guides -->
                  <tr>
                      <td style="padding:20px 35px;
                                font-family:Arial,Helvetica,sans-serif;
                                font-size:13px;
                                line-height:22px;
                                color:#333333;
                                text-align:center;">
                        Take full advantage of all the features '. $customName .' has to offer by reading our
                        <a href="https://www.ringq.com/guides/" style="color:#009FE3;text-decoration:none;">easy guides</a>:
                      </td>
                    </tr>
                    
                    <tr>
                      <td style="padding:20px 35px;
                                font-family:Arial,Helvetica,sans-serif;
                                font-weight:600;
                                font-size:13px;
                                line-height:22px;
                                color:#333333;
                                text-align:center;">
                        <a href="https://www.ringq.com/guides/team-directory/" style="color:#009FE3;text-decoration:none;">Team Directory</a>   |  
                        <a href="https://www.ringq.com/guides/voicemail/" style="color:#009FE3;text-decoration:none;">Voicemail</a>   |  
                        <a href="https://www.ringq.com/guides/dialer/" style="color:#009FE3;text-decoration:none;">The '. $customName .' Dialer</a><br>
                        <a href="https://www.ringq.com/video-conferencing/" style="color:#009FE3;text-decoration:none;">Meet Now</a>   |  
                        <a href="https://www.ringq.com/guides/talk-link/#elementor-toc__heading-anchor-0" style="color:#009FE3;text-decoration:none;">'. $customName .' Talk Link</a>   |  
                        <a href="https://www.ringq.com/guides/attended-blind-transfer/" style="color:#009FE3;text-decoration:none;">Call Transfers</a>  
                      </td>
                    </tr>

                    <tr>
                      <td style="padding:20px 35px;
                                font-family:Arial,Helvetica,sans-serif;
                                font-size:13px;
                                line-height:22px;
                                color:#333333;
                                text-align:center;">
                       Enjoy your '. $customName .' journey! <br>
                       The '. $customName .' Team
                      </td>
                    </tr>
                    

                  <!-- Footer -->
              <tfoot>
                 <tr>
                    ' . $footerTag . '
                    </td>
                 </tr>
               </tfoot>
  
                </table>
                <!-- End main container -->
  
              </td>
            </tr>
          </table>
          </body>
  
              </html>';
      }
      if ($params["type"] == 3) {
        // --- Header Logic ---
        if (isset($banner)) {
          $headerOpen = '<td style="padding:20px; display:block; height:70px; background:url(\'https://' . $banner . ' \') center/cover no-repeat;">'; 
          $headerContent = ''; // Hide logo/text if a custom banner is used
        } else {
          $headerOpen = '<td style="padding:20px; display:block; height:auto; background:url(\'https://cap.ringq.com/emailuse/WhatsApp-Image-2025-09-24-at-09.47.46.jpeg\') center/cover no-repeat;">'; 
          $headerContent = '
            <table width="100%" cellpadding="0" cellspacing="0" border="0">
                <tbody><tr>
                  <td align="left">
                    <img src="https://cap.ringq.com/emailuse/RingQlogomailer-2.png" alt="RingQ Logo" width="130" height="65" style="display:block; max-width:130px; height:65px;">
                  </td>
                  <td align="right" valign="middle">
                    <p style="font-size: 16px; font-family: sans-serif; color: #ffffff; margin: 0; padding-right: 0px; font-weight: bold;">
                      Welcome to '. $customName .'!
                    </p>
                  </td>
                </tr></tbody>
            </table>';
        }

        // --- Footer Logic ---
        $footerTag = '
            <td bgcolor="#11224D" valign="middle" style="text-align:center; padding:15px;">
                          <table align="center" border="0" cellpadding="0" cellspacing="0" width="100%">
                            <tr>
                              <td style="text-align:left; line-height:20px; padding:10px 30px; color:#8c94c4; font-weight:lighter; font-family: Arial, Helvetica, sans-serif; font-size:12px;">
                                This email was sent to 
                                  <a href="mailto:'.$to.'" style="text-decoration: underline; color:rgb(0, 159, 227) !important;">
                                        <span style="color:rgb(0, 159, 227) !important;">'.$to.'</span>
                                    </a>.
                                Update your mailing preferences from your 
                                <span style="color:#216798;">Customer Portal</span> profile.
                              </td>
                            </tr>
      
                            <tr>
                              <td style="padding:10px 30px;">
                                <table width="100%" cellpadding="0" cellspacing="0" border="0">
                                  <tr>
                                    <td align="left">
                                      <img src="https://cap.ringq.com/emailuse/RingQlogomailerfooter-2.png"
                                          alt="RingQ Logo"
                                          style="display:block; width:56.5px; height:27.01px;">
                                    </td>
                                    <td align="right">
                                      <a href="https://www.facebook.com/ringqcallcenter" style="margin-right:10px;">
                                        <img src="https://cap.ringq.com/emailuse/facebook-2.png" alt="Facebook" width="20" height="20">
                                      </a>
                                      <a href="https://www.linkedin.com/company/ringqcallcenter/" style="margin-right:10px;">
                                        <img src="https://cap.ringq.com/emailuse/linkedin-2.png" alt="LinkedIn" width="20" height="20">
                                      </a>
                                      <a href="https://www.youtube.com/@RingQ-ry6kw" style="margin-right:10px;">
                                        <img src="https://cap.ringq.com/emailuse/youtube-1.png" alt="YouTube" width="20" height="20">
                                      </a>
                                      <a href="https://x.com/RingQcloudcx" style="margin-right:10px;">
                                        <img src="https://cap.ringq.com/emailuse/twitter.png" alt="Twitter" width="20" height="20">
                                      </a>
                                      <a href="https://www.instagram.com/ringqcloudcx">
                                        <img src="https://cap.ringq.com/emailuse/instagram-1.png" alt="Instagram" width="20" height="20">
                                      </a>
                                    </td>
                                  </tr>
                                </table>
                              </td>
                            </tr>
      
                            <tr>
                              <td align="center" style="color:#216798; font-size:10px; font-weight:lighter; font-family: Arial, Helvetica, sans-serif; padding-top:10px;">
                                Copyright ©️ 2026 '. $customName .'. All Rights Reserved.
                              </td>
                            </tr>
                          </table>
                        </td>'; 

        if (isset($footer)) {
          $footerTag = '<td style="padding:20px; display:block; height:70px; background:url(\'https://' . $footer . ' \') center/cover no-repeat;"></td>'; 
        }

        $body =
        '
  <html>
      <head>
        <meta charset="UTF-8"><meta name="viewport" content="width=device-width,initial-scale=1">
        <meta http-equiv="X-UA-Compatible" content="IE=edge">
        <title>Your '. $customName .' Verification Code</title>
        <style type="text/css">body,html{margin:0!important;padding:0!important;height:100%!important;width:100%!important}*{-ms-text-size-adjust:100%;-webkit-text-size-adjust:100%}.ExternalClass{width:100%}div[style*="margin: 16px 0"]{margin:0!important}table,td{mso-table-lspace:0!important;mso-table-rspace:0!important}table{border-spacing:0!important;border-collapse:collapse!important;table-layout:fixed!important;margin:0 auto}table table table{table-layout:auto}img{-ms-interpolation-mode:bicubic}.yshortcuts a{border-bottom:none!important}a[x-apple-data-detectors]{color:inherit!important}a:link{text-decoration:none;color:rgb(123, 0, 255)}a:visited{color:#87ceeb}
        </style>
        <style type="text/css">.button-a,.button-td{transition:all .1s ease-in}.button-a:hover,.button-td:hover{background:#0089cb!important;border-color:#0089cb!important}@media screen and (max-width:600px){.email-container{width:100%!important}.fluid,.fluid-centered{max-width:100%!important;height:auto!important;margin-left:auto!important;margin-right:auto!important}.fluid-centered{margin-left:auto!important;margin-right:auto!important}.stack-column,.stack-column-center{display:block!important;width:100%!important;max-width:100%!important;direction:ltr!important}.stack-column-center{text-align:center!important}.center-on-narrow{text-align:center!important;display:block!important;margin-left:auto!important;margin-right:auto!important;float:none!important}table.center-on-narrow{display:inline-block!important}}</style>
        <link href="https://cdn.usebootstrap.com/bootstrap/4.3.1/css/bootstrap.min.css" rel="stylesheet" type="text/css">
      </head>
      <body bgcolor="#e0e0e0" width="100%" style="margin:0" yahoo="yahoo">
        <table bgcolor="#e0e0e0" cellpadding="0" cellspacing="0" border="0" height="100%" width="100%" style="border-collapse:collapse; background:#f8f9fa;">
          <tr>
            <td><center style="width: 100%;">
              <table cellspacing="0" cellpadding="0" border="0" align="center" bgcolor="#ffffff" width="600" class="email-container">
                  <thead>
                   <tr>
                    ' . $headerOpen . $headerContent . '</td>
                   </tr>
                  </thead>                  
                  <tbody>
                    <tr>
                      <td class="template-title-section" style="padding: 20px 40px 20px 40px; text-align: justify; font-family: sans-serif; font-size: 12px;  line-height: 20px; color: #555555; background-color: #ffffff">Hi, '. $params["firstName"] .' <br><br>
                      Here is your '. $customName .' verification code for you to sign into your '. $customName .' account. Enter the below code into the 
                      <span style="font-weight: 800">"Access Code"</span> field.
                      <p style="font-size:21px; margin: 20px 0px 0px 0px; color: #369ee3; font-weight:bold;">
                          <span style="color: #369ee3 !important;">' . $params["fqdn"] . '/cloudapp</span>
                        </p>
                      </td>
                    </tr>
                    
              <tr>
              <td style="background-color: #ffffff; padding-bottom: 20px">
              <table width="90%" cellpadding="0" cellspacing="0" border="0" style="margin: 0px ; background-color: #e6eeff; border-radius: 16px;">
  
                    
                    <tr>
                      <td align="center" style="padding: 20px 40px; background-color: #e6eeff; border-radius:0px 20px 20px 0px;">
                    
                        <table width="100%" cellpadding="0" cellspacing="0" style="max-width:600px; background-color:#ffffff; border-radius:12px;">
                       <tr>
                        <td align="center" style="padding: 10px 0px 20px 30px; font-family:sans-serif;">
  
                          <table cellpadding="0" cellspacing="0" style="margin:0 auto; width: 100%; color:#555555; padding: 40px; border-radius:8px; width: 100%; padding:20px;">
                            <tr>
                              <td align="center" style="font-family:sans-serif; width:100%">
  
                                <p style="text-align: center; font-size:14px; color:#6b7280; margin:12px 0; font-weight:bold;">
                                  Verification Code:
                                </p>
  
                                <table width="100%" border="0" cellspacing="0" cellpadding="0">
                                  <tr>
                                    <td align="center">
                                      <table border="0" cellspacing="0" width="100%" cellpadding="0">
                                        <tr>
                                          <td align="center" 
                                              bgcolor="#1e3a8a" 
                                              style="
                                                background-color: #1e3a8a; 
                                                background-image: linear-gradient(90deg, #1e3a8a, #38bdf8); 
                                                border-radius:20px 0 0 20px;
                                                padding: 12px 25px;
                                              ">
                                            <span style="
                                              color: #ffffff; 
                                              font-family: Arial, sans-serif; 
                                              font-size: 16px; 
                                              font-weight: bold; 
                                              text-decoration: none; 
                                              letter-spacing: 2px;
                                            ">
                                              ' . $params["forgot"] . '
                                            </span>
                                            </td>
                                        </tr>
                                      </table>
                                    </td>
                                  </tr>
                                </table>
  
                              <p style="font-size:13px; color:#6b7280; margin:12px 0 0 0;">
                                This code is valid for 15 minutes.
                              </p>
                              </td>
                            </tr>
                          </table>
                        </td>
                      </tr>
                        </table>
                                        <p style="font-size:13px; color:#6b7280; margin:25px 0 0 0; line-height:20px;">
                            If you did not request a code, or if you have any other questions or concerns,
                            please contact your system admin<br>
                            <span style="color:#318fd4; text-decoration: none">(' . $params["adminEmail"] . ') </span>
                          </p>
                          <br>
  
                          <p style="font-size:13px; color:#6b7280; margin:10px 0 20px 0;">
                            Click on this link to login automatically.
                          </p>
  
                          <table cellpadding="0" cellspacing="0" style="margin:0 auto;">
                            <tr>
                              <td style="background-color:#369ee3; border-radius:8px; text-align:center;">
                                <a
                                  href="https://' . $params["fqdn"] . '/cloudapp/?recover=' . $params["forgot"] . '&email=' . $to . '"
                                  style="
                                    display:inline-block;
                                    padding:8px 70px;
                                    font-size:12px;
                                    color:#ffffff;
                                    text-decoration:none;
                                  "
                                >
                                  Login Now
                                </a>
                              </td>
                            </tr>
                          </table>
                      </td>
                    </tr>  
                  </table>  
              </td>
          </tr>    
                    
              <tfoot>
                 <tr>
                    ' . $footerTag . '
                    </td>
                 </tr>
               </tfoot>
                  </tbody>
                </table>
              </center>
            </td>
          </tr>
        </table>
      </body>
    </html>';
}
  
    if ($params["type"] == 4) {
      if (isset($banner)) {
        $headerOpen = '<td style="padding:50px; display:block; height:70px; background:url(\'https://' . $banner . ' \') center/cover no-repeat;">'; 
      } else {
        $headerOpen = '<td style="padding:50px; display:block; height:auto; background:url(\'https://cap.ringq.com/emailuse/RingQmailer2.jpg\') center/cover no-repeat;">'; 
      }


      $body = '<html>
          <head>
              <meta charset="UTF-8"><meta name="viewport" content="width=device-width,initial-scale=1">
              <meta http-equiv="X-UA-Compatible" content="IE=edge">
              <title>Your '. $customName .' Verification Code</title>
              <style type="text/css">body,html{margin:0!important;padding:0!important;height:100%!important;width:100%!important}*{-ms-text-size-adjust:100%;-webkit-text-size-adjust:100%}.ExternalClass{width:100%}div[style*="margin: 16px 0"]{margin:0!important}table,td{mso-table-lspace:0!important;mso-table-rspace:0!important}table{border-spacing:0!important;border-collapse:collapse!important;table-layout:fixed!important;margin:0 auto!important}table table table{table-layout:auto}img{-ms-interpolation-mode:bicubic}.yshortcuts a{border-bottom:none!important}a[x-apple-data-detectors]{color:inherit!important}a:link{text-decoration:none;color:rgb(123, 0, 255)}a:visited{color:#87ceeb}
              </style>
              <style type="text/css">.button-a,.button-td{transition:all .1s ease-in}.button-a:hover,.button-td:hover{background:#0089cb!important;border-color:#0089cb!important}@media screen and (max-width:600px){.email-container{width:100%!important}.fluid,.fluid-centered{max-width:100%!important;height:auto!important;margin-left:auto!important;margin-right:auto!important}.fluid-centered{margin-left:auto!important;margin-right:auto!important}.stack-column,.stack-column-center{display:block!important;width:100%!important;max-width:100%!important;direction:ltr!important}.stack-column-center{text-align:center!important}.center-on-narrow{text-align:center!important;display:block!important;margin-left:auto!important;margin-right:auto!important;float:none!important}table.center-on-narrow{display:inline-block!important}}</style>
              <link href="https://cdn.usebootstrap.com/bootstrap/4.3.1/css/bootstrap.min.css" rel="stylesheet" type="text/css">
          </head>
          <body bgcolor="#e0e0e0" width="100%" style="margin: 0;" yahoo="yahoo">
              <table bgcolor="#e0e0e0" cellpadding="0" cellspacing="0" border="0" height="100%" width="100%" style="border-collapse:collapse;">
                <tr>
                  <td>
                    <center style="width: 100%;">
                      <div style="display:none;font-size:1px;line-height:1px;max-height:0px;max-width:0px;opacity:0;overflow:hidden;mso-hide:all;font-family: sans-serif;">Here is your voicemail notification</div>
                      <table cellspacing="0" cellpadding="0" border="0" align="center" bgcolor="#f3f3f3" width="600" class="email-container">
                     <thead>
                      <tr>
                        ' . $headerOpen . '</td>
                      </tr>
                      </thead>  
                        <tr>
                          <td style="padding: 20px 40px 20px 40px;  background-color: #ffffff; text-align: justify; font-family: sans-serif; font-size: 15px; mso-height-rule: exactly; line-height: 20px; color: #555555;">

                          <h1>Voicemail Email</h1>
                            <!-- <p> Thank you for choosing '. $customName .' for your needs, and we look forward to serving you. Should you have any questions or need further assistance, please do not hesitate to reach out to us. </p>

                            Please note that this payment link will be valid for 30 minutes.<br>  <br>    -->
                            <br>
                            <div style="text-align: left;">
                            <p>
                                You have received a voicemail from <strong>' . $params["from"] . '</strong> to <strong>' . $params["toId"] . '</strong> on <strong>' . $params["date"] . '</strong>. The message is <strong>' . $params["length"] . '</strong> long, and the audio file is attached.
                            </p>
                                <div style="text-align: left; margin-top: 25px;">
                                  ' . (!empty($params["transcription"]) ? '
                                    <div style="margin-bottom:20px;padding:15px;border-left:4px solid #FF9800;background:#f8f9fa;border-radius:4px;">
                                        <h3 style="margin:0 0 10px 0;color:#11224D;">Voicemail Transcription</h3>
                                        <div style="font-size:14px;line-height:22px;color:#555;">
                                            ' . nl2br(htmlspecialchars($params["transcription"])) . '
                                        </div>
                                    </div>
                                    ' : '') . '

                                    ' . (!empty($params["summary"]) ? '
                                    <div style="margin-bottom:20px;padding:15px;border-left:4px solid #2196F3;background:#f8f9fa;border-radius:4px;">
                                        <h3 style="margin:0 0 10px 0;color:#11224D;">AI Summary</h3>
                                        <p style="margin:0;font-size:14px;line-height:22px;">
                                            ' . nl2br(htmlspecialchars($params["summary"])) . '
                                        </p>
                                    </div>
                                    ' : '') . '

                                    ' . (!empty($params["sentiment"]) ? '
                                    <div style="margin-bottom:20px;padding:15px;border-left:4px solid #4CAF50;background:#f8f9fa;border-radius:4px;">
                                        <h3 style="margin:0 0 10px 0;color:#11224D;">Sentiment Analysis</h3>
                                        <p style="margin:0;font-size:14px;line-height:22px;">
                                            ' . htmlspecialchars($params["sentiment"]) . '
                                        </p>
                                    </div>
                                    ' : '') . '     
                                </div>
                      </div>
                          </td>
                        <tr>
                          <td background="" bgcolor="11224D" valign="middle" style="text-align: center; background-position: center center !important; background-size: cover !important;">
                            <table align="center" border="0" cellpadding="0" cellspacing="0" width="100%">
                              <tr style="height: 100px;">

                                <td valign="middle" style="text-align: center; padding: 20px 40px 20px 40px; font-family: Segoe, Segoe UI, DejaVu Sans, Trebuchet MS, Verdana, sans-serif; font-size: 20px; mso-height-rule: exactly; line-height: 20px; color: #ffffff;">

                                </td>
                              </tr>
                          </td>
                        </tr>
                      </table>

                      <table align="center" width="600" class="email-container">
                        <tr>
                          <td bgcolor="#e0e0e0" style="padding: 20px 20px;width: 100%;font-size: 12px; font-family: arial; mso-height-rule: exactly; line-height:18px; text-align: center; color: #2d2a4a;">If you haven’t created a '. $customName .' account then please <a href="https://www.ringq.com/contact/">let us know</a> straight away and we’ll sort it out for you. <br>
                            <br> Copyright © 2025 '. $customName .'. All Rights Reserved. <br>

                          </td>
                        </tr>
                      </table>
                    </center>
                  </td>
                </tr>
              </table>
          </body>
          </html>';
    }
    if ($params["type"] == 5) {

      if (isset($banner)) {
        $headerOpen = '<td style="padding:50px; display:block; height:70px; background:url(\'https://' . $banner . ' \') center/cover no-repeat;">'; 
      } else {
        $headerOpen = '<td style="padding:50px; display:block; height:auto; background:url(\'https://cap.ringq.com/emailuse/RingQmailer2.jpg\') center/cover no-repeat;">'; 
      }

      // --- Footer Logic ---

      $body = '<html>
          <head>
              <meta charset="UTF-8"><meta name="viewport" content="width=device-width,initial-scale=1">
              <meta http-equiv="X-UA-Compatible" content="IE=edge">
              <title>Your '. $customName .' Verification Code</title>
              <style type="text/css">body,html{margin:0!important;padding:0!important;height:100%!important;width:100%!important}*{-ms-text-size-adjust:100%;-webkit-text-size-adjust:100%}.ExternalClass{width:100%}div[style*="margin: 16px 0"]{margin:0!important}table,td{mso-table-lspace:0!important;mso-table-rspace:0!important}table{border-spacing:0!important;border-collapse:collapse!important;table-layout:fixed!important;margin:0 auto!important}table table table{table-layout:auto}img{-ms-interpolation-mode:bicubic}.yshortcuts a{border-bottom:none!important}a[x-apple-data-detectors]{color:inherit!important}a:link{text-decoration:none;color:rgb(123, 0, 255)}a:visited{color:#87ceeb}
              </style>
              <style type="text/css">.button-a,.button-td{transition:all .1s ease-in}.button-a:hover,.button-td:hover{background:#0089cb!important;border-color:#0089cb!important}@media screen and (max-width:600px){.email-container{width:100%!important}.fluid,.fluid-centered{max-width:100%!important;height:auto!important;margin-left:auto!important;margin-right:auto!important}.fluid-centered{margin-left:auto!important;margin-right:auto!important}.stack-column,.stack-column-center{display:block!important;width:100%!important;max-width:100%!important;direction:ltr!important}.stack-column-center{text-align:center!important}.center-on-narrow{text-align:center!important;display:block!important;margin-left:auto!important;margin-right:auto!important;float:none!important}table.center-on-narrow{display:inline-block!important}}</style>
              <link href="https://cdn.usebootstrap.com/bootstrap/4.3.1/css/bootstrap.min.css" rel="stylesheet" type="text/css">
          </head>
          <body bgcolor="#e0e0e0" width="100%" style="margin: 0;" yahoo="yahoo">
              <table bgcolor="#e0e0e0" cellpadding="0" cellspacing="0" border="0" height="100%" width="100%" style="border-collapse:collapse;">
                <tr>
                  <td>
                    <center style="width: 100%;">
                      <div style="display:none;font-size:1px;line-height:1px;max-height:0px;max-width:0px;opacity:0;overflow:hidden;mso-hide:all;font-family: sans-serif;">Here is your voicemail notification</div>
                      <table cellspacing="0" cellpadding="0" border="0" align="center" bgcolor="#f3f3f3" width="600" class="email-container">
                          <thead>
                          <tr>
                            ' . $headerOpen .'</td>
                          </tr>
                          </thead>                  
                        <tr>
                          <td style="padding: 20px 40px 20px 40px;  background-color: #ffffff; text-align: justify; font-family: sans-serif; font-size: 15px; mso-height-rule: exactly; line-height: 20px; color: #555555;">


                    <h1>Voicemail Email</h1>
                      <!-- <p> Thank you for choosing '. $customName .' for your needs, and we look forward to serving you. Should you have any questions or need further assistance, please do not hesitate to reach out to us. </p>

                      Please note that this payment link will be valid for 30 minutes.<br>  <br>    -->
                      <br>
                      <div style="text-align: left;">
                            <p>
                                You have received a voicemail from <strong>' . $params["from"] . '</strong> to <strong>' . $params["toId"] . '</strong> on <strong>' . $params["date"] . '</strong>. The message is <strong>' . $params["length"] . '</strong> long, and the audio file is attached.
                            </p>
                                <div style="text-align: left; margin-top: 25px;">
                                  ' . (!empty($params["transcription"]) ? '
                                    <div style="margin-bottom:20px;padding:15px;border-left:4px solid #FF9800;background:#f8f9fa;border-radius:4px;">
                                        <h3 style="margin:0 0 10px 0;color:#11224D;">Voicemail Transcription</h3>
                                        <div style="font-size:14px;line-height:22px;color:#555;">
                                            ' . nl2br(htmlspecialchars($params["transcription"])) . '
                                        </div>
                                    </div>
                                    ' : '') . '

                                    ' . (!empty($params["summary"]) ? '
                                    <div style="margin-bottom:20px;padding:15px;border-left:4px solid #2196F3;background:#f8f9fa;border-radius:4px;">
                                        <h3 style="margin:0 0 10px 0;color:#11224D;">AI Summary</h3>
                                        <p style="margin:0;font-size:14px;line-height:22px;">
                                            ' . nl2br(htmlspecialchars($params["summary"])) . '
                                        </p>
                                    </div>
                                    ' : '') . '

                                    ' . (!empty($params["sentiment"]) ? '
                                    <div style="margin-bottom:20px;padding:15px;border-left:4px solid #4CAF50;background:#f8f9fa;border-radius:4px;">
                                        <h3 style="margin:0 0 10px 0;color:#11224D;">Sentiment Analysis</h3>
                                        <p style="margin:0;font-size:14px;line-height:22px;">
                                            ' . htmlspecialchars($params["sentiment"]) . '
                                        </p>
                                    </div>
                                    ' : '') . '     
                                </div>
                      </div>
                          </td>
                        <tr>
                          <td background="" bgcolor="11224D" valign="middle" style="text-align: center; background-position: center center !important; background-size: cover !important;">
                            <table align="center" border="0" cellpadding="0" cellspacing="0" width="100%">
                              <tr style="height: 100px;">

                                <td valign="middle" style="text-align: center; padding: 20px 40px 20px 40px; font-family: Segoe, Segoe UI, DejaVu Sans, Trebuchet MS, Verdana, sans-serif; font-size: 20px; mso-height-rule: exactly; line-height: 20px; color: #ffffff;">

                                </td>
                              </tr>
                          </td>
                        </tr>
                      </table>

                      <table align="center" width="600" class="email-container">
                        <tr>
                          <td bgcolor="#e0e0e0" style="padding: 20px 20px;width: 100%;font-size: 12px; font-family: arial; mso-height-rule: exactly; line-height:18px; text-align: center; color: #2d2a4a;">If you haven’t created a '. $customName .' account then please <a href="https://www.ringq.com/contact/">let us know</a> straight away and we’ll sort it out for you. <br>
                            <br> Copyright © 2025 '. $customName .'. All Rights Reserved. <br>

                          </td>
                        </tr>
                      </table>
                    </center>
                  </td>
                </tr>
              </table>
          </body>
          </html>';
    }
    if ($params["type"] == 6) {
      $body =
        '<!doctype html>
          <html>
          <head>
          <meta charset="UTF-8">
          <!-- utf-8 works for most cases -->
          <meta name="viewport" content="width=device-width, initial-scale=1.0">
          <meta http-equiv="X-UA-Compatible" content="IE=edge">
          <title>'. $customName .' verification email</title>
          <style type="text/css">
          html,
          body {
            margin: 0 !important;
            padding: 0 !important;
            height: 100% !important;
            width: 100% !important;
          }

          * {
            -ms-text-size-adjust: 100%;
            -webkit-text-size-adjust: 100%;
          }

          .ExternalClass {
            width: 100%;
          }

          div[style*="margin: 16px 0"] {
            margin: 0 !important;
          }

          table,
          td {
            mso-table-lspace: 0pt !important;
            mso-table-rspace: 0pt !important;
          }

          table {
            border-spacing: 0 !important;
            border-collapse: collapse !important;
            table-layout: fixed !important;
            margin: 0 auto !important;
          }

          table table table {
            table-layout: auto;
          }

          img {
            -ms-interpolation-mode: bicubic;
          }

          .yshortcuts a {
            border-bottom: none !important;
          }

          a[x-apple-data-detectors] {
            color: inherit !important;
          }

          a:link {
            text-decoration: none;
            color: #009FE3
          }

          a:visited {
            color: #009FE3;
          }
          </style>
          <style type="text/css">
          .button-td,
          .button-a {
            transition: all 100ms ease-in;
          }

          .button-td:hover,
          .button-a:hover {
            background: #009FE3 !important;
            border-color: #009FE3 !important;
          }

          @media screen and (max-width: 600px) {
            .email-container {
              width: 100% !important;
            }

            .fluid,
            .fluid-centered {
              max-width: 100% !important;
              height: auto !important;
              margin-left: auto !important;
              margin-right: auto !important;
            }

            .fluid-centered {
              margin-left: auto !important;
              margin-right: auto !important;
            }

            .stack-column,
            .stack-column-center {
              display: block !important;
              width: 100% !important;
              max-width: 100% !important;
              direction: ltr !important;
            }

            .stack-column-center {
              text-align: center !important;
            }

            .center-on-narrow {
              text-align: center !important;
              display: block !important;
              margin-left: auto !important;
              margin-right: auto !important;
              float: none !important;
            }

            table.center-on-narrow {
              display: inline-block !important;
            }
          }
          </style>
          </head>
          <body bgcolor="#e0e0e0" width="100%" style="margin:0" yahoo="yahoo">
              <table bgcolor="#e0e0e0" cellpadding="0" cellspacing="0" border="0" height="100%" width="100%" style="border-collapse:collapse;">
                    <tr>
                      <td>
                        <center style="width: 100%;">
                          <table cellspacing="0" cellpadding="0" border="0" align="center" bgcolor="#ffffff" width="600" class="email-container">
                            <tr>
                              <td class="full-width-image">
                    <img src="https://apiringq.ringq.io/img/mailerimg.jpg" width="600" alt="alt_text" border="0" align="center" style="width: 100%; max-width: 600px; height: auto;">
                  </td>
                        </tr>
                        <tr>
                          <td class="template-title-section" style="padding: 20px 40px 0px 40px; text-align: center; font-family: sans-serif; font-size: 11.5px;  line-height: 20px; color: #11224d; background-color: #ffffff">
                        <p style="color:#747e97">Greetings!</p>
                  <p style="font-weight:bolder;"> You have been invited to join  <a href="' .
        $params["meetingId"] .
        '" target="_blank">' .
        $params["meetingName"] .
        '</a><br>
                  scheduled for ' .
        $params["startDate"] .
        " to " .
        $params["endDate"] .
        '.</p>
                  <p style="font-weight:bolder; margin-top: 20px;">Meeting Decription: <br>' .
        $params["meetingDescription"] .
        '.</p>
                  <p style="font-weight:bolder; margin-top: 20px;"> This meeting was scheduled by ' .
        $params["hostName"] .
        '.</p>
                  <span style="color:#747e97">To join the meeting, click on the link below or copy and paste it into your browser:</span>
                  <br>
                  <a href="" style="text-decoration: none; color: #009fe3; font-weight: bold; display: inline-flex; align-items: center;">
                  <a href="' .
        $params["meetingId"] .
        '" target="_blank">Meeting Link</a>
                  <img src="https://apiringq.ringq.io/img/copypaste.png" alt="Copy Paste" style="margin-left: 5px; height: 10; width: 10;">
                  </a>
                  <br>
                  <p style="color:#747e97">Enjoy the meeting,<br>The '. $customName .' Team</p><br>
                              </td>
                            </tr>
                          <tr>
                              <td class=" template-content-section" style="text-align: justify; font-family: sans-serif; font-size: 9px; line-height: 20px; color: #555555; background-color: #ffffff">

                                  <div style="margin: 10px; padding: 10px; background-color: rgba(246,246,246,255); width:auto; text-align: center; ">
                                      <span style="color:#747e97"> Copyright © 2025 '. $customName .'. All Rights Reserved.</span>
                                  </div>
                              </td>
                            </tr>
                          </table>
                        </center>
                      </td>
                    </tr>
              </table>
          </body>
          </html>';
    }
    if ($params["type"] == 9) {
      $breakOutCallsHtml = '<tr>
      <td style="padding: 20px 40px; background-color: #ffffff; text-align: left; font-family: sans-serif; font-size: 15px; color: #555555; line-height: 20px;">
          <h4>Queue Call(s) (Unserved)</h4>
          <table width="100%" border="0" cellpadding="5" cellspacing="0" style="background: #151b54; color: white; font-size: larger; font-family: \'Franklin Gothic Medium\', \'Arial Narrow\', Arial, sans-serif; text-align: left;">
              <thead>
                  <tr>
                      <th>Caller</th>
                      <th>Start</th>
                      <th>End</th>
                      <th>Duration</th>
                      <th>Queue</th>
                  </tr>
              </thead>
              <tbody>';
      $breakOutCalls = $params["breakOutCalls"];
      foreach ($breakOutCalls as $breakOutCall) {
        $breakOutCallsHtml .= '<tr class="row-line" style="text-align: center">
              <td scope="row";>' . $breakOutCall["source_number"] . '</td>
              <td scope="row";>' . $breakOutCall["start"] . '</td>
              <td scope="row";>' . $breakOutCall["end"] . '</td>
              <td scope="row";>' . $breakOutCall["duration"] . '</td>
              <td scope="row";>' . $breakOutCall["destination_number"] . '</td>   
          </tr>';
      }
      if (empty($breakOutCalls)) {
        $breakOutCallsHtml = '';
      } else {
        $breakOutCallsHtml .= '</tbody>
                  </table>
              </td>
          </tr>';
      }
      $missedCallsHtml = '<tr>
      <td style="padding: 20px 40px; background-color: #ffffff; text-align: left; font-family: sans-serif; font-size: 15px; color: #555555; line-height: 20px;">
          <h4>Queue Call(s) (Missed)</h4>
          <table width="100%" border="0" cellpadding="5" cellspacing="0" style="background: #151b54; color: white; font-size: larger; font-family: \'Franklin Gothic Medium\', \'Arial Narrow\', Arial, sans-serif; text-align: left;">
              <thead>
                  <tr>
                      <th>Caller</th>
                      <th>Start</th>
                      <th>End</th>
                      <th>Duration</th>
                      <th>Queue</th>
                  </tr>
              </thead>
              <tbody>';
      $missedCalls = $params["missedCalls"];
      foreach ($missedCalls as $missedCall) {
        $missedCallsHtml .= '<tr class="row-line" style="text-align: center">
              <td scope="row";>' . $missedCall["source_number"] . '</td>
              <td scope="row";>' . $missedCall["start"] . '</td>
              <td scope="row";>' . $missedCall["end"] . '</td>
              <td scope="row";>' . $missedCall["duration"] . '</td>
              <td scope="row";>' . $missedCall["destination_number"] . '</td>   
          </tr>';
      }
      if (empty($missedCalls)) {
        $missedCallsHtml = '';
      } else {
        $missedCallsHtml .= '</tbody>
                  </table>
              </td>
          </tr>';
      }
      $slaCallsHtml = '<tr>
          <td style="padding: 20px 40px; background-color: #ffffff; text-align: left; font-family: sans-serif; font-size: 15px; color: #555555; line-height: 20px;">
              <h4>Queue Call(s) (SLA Breached)</h4>
              <table width="100%" border="0" cellpadding="5" cellspacing="0" style="background: #151b54; color: white; font-size: larger; font-family: \'Franklin Gothic Medium\', \'Arial Narrow\', Arial, sans-serif; text-align: left;">
                  <thead>
                      <tr>
                          <th>Caller</th>
                          <th>Start</th>
                          <th>End</th>
                          <th>Duration</th>
                          <th>Queue</th>
                      </tr>
                  </thead>
                  <tbody>';
      $slaCalls = $params["slaCalls"];
      foreach ($slaCalls as $slaCall) {
        $slaCallsHtml .= '<tr class="row-line" style="text-align: center">
              <td scope="row";>' . $slaCall["source_number"] . '</td>
              <td scope="row";>' . $slaCall["start"] . '</td>
              <td scope="row";>' . $slaCall["end"] . '</td>
              <td scope="row";>' . $slaCall["duration"] . '</td>
              <td scope="row";>' . $slaCall["destination_number"] . '</td>   
          </tr>';
      }
      if (empty($slaCalls)) {
        $slaCallsHtml = '';
      } else {
        $slaCallsHtml .= '</tbody>
                  </table>
              </td>
          </tr>';
      }
      $body = '<!DOCTYPE html>
              <html lang="en">
              <head>
                  <meta charset="UTF-8">
                  <meta name="viewport" content="width=device-width, initial-scale=1">
                  <meta http-equiv="X-UA-Compatible" content="IE=edge">
                  <title>Your '. $customName .' Verification Code</title>
                  <style type="text/css">
                      body, html {
                          margin: 0 !important;
                          padding: 0 !important;
                          height: 100% !important;
                          width: 100% !important;
                      }
                      * {
                          -ms-text-size-adjust: 100%;
                          -webkit-text-size-adjust: 100%;
                      }
                      .ExternalClass {
                          width: 100%;
                      }
                      div[style*="margin: 16px 0"] {
                          margin: 0 !important;
                      }
                      table, td {
                          mso-table-lspace: 0 !important;
                          mso-table-rspace: 0 !important;
                      }
                      table {
                          border-spacing: 0 !important;
                          border-collapse: collapse !important;
                          table-layout: fixed !important;
                          margin: 0 auto !important;
                      }
                      table table table {
                          table-layout: auto;
                      }
                      img {
                          -ms-interpolation-mode: bicubic;
                      }
                      .yshortcuts a {
                          border-bottom: none !important;
                      }
                      a[x-apple-data-detectors] {
                          color: inherit !important;
                      }
                      a:link {
                          text-decoration: none;
                          color: rgb(123, 0, 255);
                      }
                      a:visited {
                          color: #87ceeb;
                      }
                      .button-a, .button-td {
                          transition: all 0.1s ease-in;
                      }
                      .button-a:hover, .button-td:hover {
                          background: #0089cb !important;
                          border-color: #0089cb !important;
                      }
                      @media screen and (max-width: 600px) {
                          .email-container {
                              width: 100% !important;
                          }
                          .fluid, .fluid-centered {
                              max-width: 100% !important;
                              height: auto !important;
                              margin-left: auto !important;
                              margin-right: auto !important;
                          }
                          .stack-column, .stack-column-center {
                              display: block !important;
                              width: 100% !important;
                              max-width: 100% !important;
                              direction: ltr !important;
                          }
                          .stack-column-center {
                              text-align: center !important;
                          }
                          .center-on-narrow {
                              text-align: center !important;
                              display: block !important;
                              margin-left: auto !important;
                              margin-right: auto !important;
                              float: none !important;
                          }
                          table.center-on-narrow {
                              display: inline-block !important;
                          }
                      }
                  </style>
              </head>
              <body bgcolor="#e0e0e0" style="margin: 0; padding: 0; width: 100%; background-color: #e0e0e0;">
                  <center style="width: 100%; background-color: #e0e0e0;">
                      <div style="display:none; font-size:1px; line-height:1px; max-height:0px; max-width:0px; opacity:0; overflow:hidden; mso-hide:all; font-family: sans-serif;">
                          Please see the missed queue call information.
                      </div>
                      <table align="center" bgcolor="#f3f3f3" width="600" class="email-container" style="border-collapse: collapse; max-width: 600px;">
                          <!-- Header Image -->
                          <tr>
                              <td style="padding: 0;">
                                  <img src="https://cap.ringq.com/emailuse/RingQmailer2.jpg" width="600" alt="alt_text" style="width: 100%; max-width: 600px; height: auto; display: block;">
                              </td>
                          </tr>
                          <!-- Section: Calls who left -->
                          ' . $breakOutCallsHtml . '
                          <!-- Section: Missed Email -->
                          ' . $missedCallsHtml . '
                          <!-- Section: SLA Email -->
                          ' . $slaCallsHtml . '
                          <!-- Footer -->
                          <tr>
                              <td style="background-color: #11224D; padding: 20px; text-align: center; color: #ffffff; font-family: Segoe, Segoe UI, DejaVu Sans, Trebuchet MS, Verdana, sans-serif; font-size: 12px; line-height: 18px;">
                                  If you haven’t created a '. $customName .' account, then please <a href="https://www.ringq.com/contact/" style="color: #ffffff; text-decoration: underline;">let us know</a> straight away, and we’ll sort it out for you.<br><br>
                                  Copyright © 2024 '. $customName .'. All Rights Reserved.
                              </td>
                          </tr>
                      </table>
                  </center>
              </body>
            </html>';
    }
    if ($params["type"] == 11) {
      if (isset($banner)) {
        $headerOpen = '<td style="padding:20px; display:block; height:70px; background:url(\'https://' . $banner . ' \') center/cover no-repeat;">'; 
      } else {
        $headerOpen = '<td style="padding:20px; display:block; height:auto; background:url(\'https://cap.ringq.com/emailuse/RingQmailer2.jpg\') center/cover no-repeat;">'; 
      }

      $body = '<html>
          <head>
              <meta charset="UTF-8"><meta name="viewport" content="width=device-width,initial-scale=1">
              <meta http-equiv="X-UA-Compatible" content="IE=edge">
              <title>Your '. $customName .' Verification Code</title>
              <style type="text/css">body,html{margin:0!important;padding:0!important;height:100%!important;width:100%!important}*{-ms-text-size-adjust:100%;-webkit-text-size-adjust:100%}.ExternalClass{width:100%}div[style*="margin: 16px 0"]{margin:0!important}table,td{mso-table-lspace:0!important;mso-table-rspace:0!important}table{border-spacing:0!important;border-collapse:collapse!important;table-layout:fixed!important;margin:0 auto!important}table table table{table-layout:auto}img{-ms-interpolation-mode:bicubic}.yshortcuts a{border-bottom:none!important}a[x-apple-data-detectors]{color:inherit!important}a:link{text-decoration:none;color:rgb(123, 0, 255)}a:visited{color:#87ceeb}
              </style>
              <style type="text/css">.button-a,.button-td{transition:all .1s ease-in}.button-a:hover,.button-td:hover{background:#0089cb!important;border-color:#0089cb!important}@media screen and (max-width:600px){.email-container{width:100%!important}.fluid,.fluid-centered{max-width:100%!important;height:auto!important;margin-left:auto!important;margin-right:auto!important}.fluid-centered{margin-left:auto!important;margin-right:auto!important}.stack-column,.stack-column-center{display:block!important;width:100%!important;max-width:100%!important;direction:ltr!important}.stack-column-center{text-align:center!important}.center-on-narrow{text-align:center!important;display:block!important;margin-left:auto!important;margin-right:auto!important;float:none!important}table.center-on-narrow{display:inline-block!important}}</style>
              <link href="https://cdn.usebootstrap.com/bootstrap/4.3.1/css/bootstrap.min.css" rel="stylesheet" type="text/css">
          </head>
          <body bgcolor="#e0e0e0" width="100%" style="margin: 0;" yahoo="yahoo">
              <table bgcolor="#e0e0e0" cellpadding="0" cellspacing="0" border="0" height="100%" width="100%" style="border-collapse:collapse;">
              <tr>
              <td>
                <center style="width: 100%;">
                  <div style="display:none;font-size:1px;line-height:1px;max-height:0px;max-width:0px;opacity:0;overflow:hidden;mso-hide:all;font-family: sans-serif;">Here is your voicemail notification</div>
                  <table cellspacing="0" cellpadding="0" border="0" align="center" bgcolor="#f3f3f3" width="600" class="email-container">
                   <thead>
                          <tr>
                            ' . $headerOpen . '</td>
                          </tr>
                          </thead>  
                    <tr>
                      <td style="padding: 20px 40px 20px 40px;  background-color: #ffffff; text-align: justify; font-family: sans-serif; font-size: 15px; mso-height-rule: exactly; line-height: 20px; color: #555555;">
                      <h1>Voicemail</h1>
                      </td>
                    <tr>
                      <td background="" bgcolor="11224D" valign="middle" style="text-align: center; background-position: center center !important; background-size: cover !important;">
                        <table align="center" border="0" cellpadding="0" cellspacing="0" width="100%">
                          <tr style="height: 100px;">
                            <td valign="middle" style="text-align: center; padding: 20px 40px 20px 40px; font-family: Segoe, Segoe UI, DejaVu Sans, Trebuchet MS, Verdana, sans-serif; font-size: 20px; mso-height-rule: exactly; line-height: 20px; color: #ffffff;">
                            </td>
                          </tr>
                      </td>
                    </tr>
                  </table>
                  <table border="1" cellpadding="8" cellspacing="0">
                    <thead>
                      <tr>
                        <th colspan="2">Voicemail Details</th>
                      </tr>
                    </thead>
                    <tbody>
                      <tr>
                        <td><strong>From</strong></td>
                        <td>' . $params["from"] . '</td>
                      </tr>
                      <tr>
                        <td><strong>To</strong></td>
                        <td>' . $params["to"] . '</td>
                      </tr>
                      <tr>
                        <td><strong>Received</strong></td>
                        <td>' . $params["date"] . '</td>
                      </tr>
                      <tr>
                        <td><strong>Length</strong></td>
                        <td>' . $params["length"] . '</td>
                      </tr>
                      <tr>
                        <td><strong>Message</strong></td>
                        <td>Attached</td>
                      </tr>
                    </tbody>
                  </table>
                  <table align="center" width="600" class="email-container">
                    <tr>
                      <td bgcolor="#e0e0e0" style="padding: 20px 20px;width: 100%;font-size: 12px; font-family: arial; mso-height-rule: exactly; line-height:18px; text-align: center; color: #2d2a4a;">If you haven’t created a '. $customName .' account then please <a href="https://www.ringq.com/contact/">let us know</a> straight away and we’ll sort it out for you. <br>
                        <br> Copyright © 2025 '. $customName .'. All Rights Reserved. <br>
                      </td>
                    </tr>
                  </table>
                </center>
              </td>
              </tr>
              </table>
          </body>
          </html>';
    }
    // System usage (12) and concurrent call limit (13) alerts, in the same
    // branded layout as the scheduled report email.
    if ($params["type"] == 12 || $params["type"] == 13) {
      if (isset($banner)) {
        $headerOpen = '<td style="padding:20px; display:block; height:70px; background:url(\'https://' . $banner . ' \') center/cover no-repeat;">';
        $headerContent = '';
      } else {
        $headerOpen = '<td style="padding:20px; display:block; height:auto; background:url(\'https://cap.ringq.com/emailuse/WhatsApp-Image-2025-09-24-at-09.47.46.jpeg\') center/cover no-repeat;">';
        $headerContent = '
          <table width="100%" cellpadding="0" cellspacing="0" border="0">
              <tbody><tr>
                <td align="left">
                  <img src="https://cap.ringq.com/emailuse/RingQlogomailer-2.png" alt="RingQ Logo" width="130" height="65" style="display:block; max-width:130px; height:65px;">
                </td>
              </tr></tbody>
          </table>';
      }

      $footerTag = '
          <td bgcolor="#11224D" valign="middle" style="text-align:center; padding:15px;">
                        <table align="center" border="0" cellpadding="0" cellspacing="0" width="100%">
                          <tr>
                            <td style="text-align:left; line-height:20px; padding:10px 30px; color:#8c94c4; font-weight:lighter; font-family: Arial, Helvetica, sans-serif; font-size:12px;">
                              This email was sent to
                                <a href="mailto:'.$to.'" style="text-decoration: underline; color:rgb(0, 159, 227) !important;">
                                        <span style="color:rgb(0, 159, 227) !important;">'.$to.'</span>
                                    </a>.
                              Update your mailing preferences from your
                              <span style="color:#216798;">Customer Portal</span> profile.
                            </td>
                          </tr>
                          <tr>
                            <td style="padding:10px 30px;">
                              <table width="100%" cellpadding="0" cellspacing="0" border="0">
                                <tr>
                                  <td align="left">
                                    <img src="https://cap.ringq.com/emailuse/RingQlogomailerfooter-2.png"
                                        alt="RingQ Logo"
                                        style="display:block; width:56.5px; height:27.01px;">
                                  </td>
                                  <td align="right">
                                    <a href="https://www.facebook.com/ringqcallcenter" style="margin-right:10px;">
                                      <img src="https://cap.ringq.com/emailuse/facebook-2.png" alt="Facebook" width="20" height="20">
                                    </a>
                                    <a href="https://www.linkedin.com/company/ringqcallcenter/" style="margin-right:10px;">
                                      <img src="https://cap.ringq.com/emailuse/linkedin-2.png" alt="LinkedIn" width="20" height="20">
                                    </a>
                                    <a href="https://www.youtube.com/@RingQ-ry6kw" style="margin-right:10px;">
                                      <img src="https://cap.ringq.com/emailuse/youtube-1.png" alt="YouTube" width="20" height="20">
                                    </a>
                                    <a href="https://x.com/RingQcloudcx" style="margin-right:10px;">
                                      <img src="https://cap.ringq.com/emailuse/twitter.png" alt="Twitter" width="20" height="20">
                                    </a>
                                    <a href="https://www.instagram.com/ringqcloudcx">
                                      <img src="https://cap.ringq.com/emailuse/instagram-1.png" alt="Instagram" width="20" height="20">
                                    </a>
                                  </td>
                                </tr>
                              </table>
                            </td>
                          </tr>
                          <tr>
                            <td align="center" style="color:#216798; font-size:10px; font-weight:lighter; font-family: Arial, Helvetica, sans-serif; padding-top:10px;">
                              Copyright ©️ 2026 '. $customName .' . All Rights Reserved.
                            </td>
                          </tr>
                        </table>
                      </td>';

      if (isset($footer)) {
        $footerTag = '<td style="padding:20px; display:block; height:70px; background:url(\'https://' . $footer . ' \') center/cover no-repeat;"></td>';
      }

      $esc = function ($v) { return htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8'); };
      $num = function ($v) { return rtrim(rtrim(number_format((float) $v, 1, '.', ''), '0'), '.'); };
      $pill = function ($over) {
        return $over
          ? '<span style="display:inline-block; padding:3px 10px; border-radius:10px; background:#fdecea; color:#c62828; font-size:11px; font-weight:bold; white-space:nowrap;">Over limit</span>'
          : '<span style="display:inline-block; padding:3px 10px; border-radius:10px; background:#e8f5e9; color:#2e7d32; font-size:11px; font-weight:bold; white-space:nowrap;">OK</span>';
      };
      $server = trim((string) ($params["hostname"] ?? ($licenseConfig['data']['fqdn'] ?? '')));
      $when = !empty($params["unix"]) && is_numeric($params["unix"]) ? (int) $params["unix"] : time();
      $info = [];
      $rows = '';

      $instance = $params["type"] == 13 ? trim((string) ($params["subdomain"] ?? '')) : $server;
      if ($params["type"] == 12) {
        $title = 'System usage alert';
        $over = [];
        foreach ([["CPU", "cpu", "cpuPercentage", "cpuEnabled"], ["Memory (RAM)", "ram", "ramPercentage", "ramEnabled"], ["Disk", "disk", "diskPercentage", "diskEnabled"]] as $m) {
          $value = $params[$m[1]] ?? null;
          if ($value === null || $value === '' || (isset($params[$m[3]]) && !$params[$m[3]])) {
            continue;
          }
          $value = max(0, min(100, (float) $value));
          $limit = isset($params[$m[2]]) && is_numeric($params[$m[2]]) ? (float) $params[$m[2]] : null;
          $isOver = $limit !== null && $value >= $limit;
          if ($isOver) {
            $over[] = $m[0];
          }
          $barColor = $isOver ? '#d32f2f' : '#0459A1';
          $barWidth = max(1, (int) round($value));
          $rows .= '
                      <tr>
                        <td style="padding:14px; border-bottom:1px solid #e6e9f0; font-family:Arial,Helvetica,sans-serif;">
                          <table width="100%" cellpadding="0" cellspacing="0" border="0">
                            <tr>
                              <td style="font-size:13px; font-weight:bold; color:#11224D;">' . $m[0] . '</td>
                              <td align="right">' . $pill($isOver) . '</td>
                            </tr>
                          </table>
                          <table width="100%" cellpadding="0" cellspacing="0" border="0" style="margin:8px 0 6px 0 !important; table-layout:fixed;">
                            <tr>
                              <td width="' . $barWidth . '%" height="8" bgcolor="' . $barColor . '" style="height:8px; line-height:8px; font-size:1px; border-radius:4px 0 0 4px;">&nbsp;</td>
                              ' . ($barWidth < 100 ? '<td height="8" bgcolor="#e6e9f0" style="height:8px; line-height:8px; font-size:1px; border-radius:0 4px 4px 0;">&nbsp;</td>' : '') . '
                            </tr>
                          </table>
                          <div style="font-size:12px; color:#555555;"><strong style="color:' . ($isOver ? '#c62828' : '#11224D') . ';">' . $num($value) . '% used</strong>' . ($limit !== null ? ' &nbsp;&middot;&nbsp; Alert limit ' . $num($limit) . '%' : '') . '</div>
                        </td>
                      </tr>';
        }
        $what = count($over) > 1
          ? implode(', ', array_slice($over, 0, -1)) . ' and ' . end($over) . ' usage are'
          : (count($over) === 1 ? $over[0] . ' usage is' : 'Usage is');
        $intro = $what . ' above the alert limit you set' . ($server !== '' ? ' on <strong>' . $esc($server) . '</strong>' : '') . '. Please check the server before it affects calls.';
        if ($server !== '') {
          $info[] = '<strong style="color:#11224D;">Instance (FQDN):</strong> ' . $esc($server);
        }
        $info[] = '<strong style="color:#11224D;">Checked at:</strong> ' . $esc(date('d M Y, h:i A T', $when));
        $note = 'Values are averaged over about the last 15 minutes. While usage stays above the limit you receive at most one alert per hour. To change the limits or recipients, open Email Notifications in ' . $esc($customName) . '.';
      } else {
        $title = 'Concurrent call limit reached';
        $subdomain = trim((string) ($params["subdomain"] ?? ''));
        $intro = 'The concurrent call limit was reached' . ($subdomain !== '' ? ' on <strong>' . $esc($subdomain) . '</strong>' : '') . '. Calls above the limit may not connect until active calls end.';
        foreach ([["Instance (FQDN)", $subdomain], ["Active calls", $params["concurrency"] ?? ''], ["Call limit", $params["concurrencyLimit"] ?? ''], ["Destination", $params["destination"] ?? ''], ["Caller number", $params["callerNumber"] ?? '']] as $f) {
          if (trim((string) $f[1]) !== '') {
            $info[] = '<strong style="color:#11224D;">' . $f[0] . ':</strong> ' . $esc($f[1]);
          }
        }
        $info[] = '<strong style="color:#11224D;">Time:</strong> ' . $esc(date('d M Y, h:i A T', $when));
        $note = 'You receive this because your address is on the concurrent call limit alert list. To change the recipients, open Email Notifications in ' . $esc($customName) . '.';
      }

      $link = $server !== ''
        ? '<div style="height:22px; line-height:22px; font-size:1px;">&nbsp;</div><table cellpadding="0" cellspacing="0" border="0" align="left" style="margin:0 0 4px 0 !important;"><tr><td bgcolor="#0459A1" style="border-radius:6px;">
             <a href="https://' . $esc($server) . '/cloudapp/" target="_blank" style="display:inline-block; padding:10px 22px; font-family:Arial,Helvetica,sans-serif; font-size:13px; font-weight:bold; color:#ffffff !important; text-decoration:none;"><span style="color:#ffffff !important;">Open Dashboard</span></a>
           </td></tr></table>'
        : '';

      $body = '<head>
              <meta charset="UTF-8"><meta name="viewport" content="width=device-width,initial-scale=1">
                <meta http-equiv="X-UA-Compatible" content="IE=edge">
                <title>' . $esc($subject) . '</title>
                <style type="text/css">
                body,html{margin:0!important;padding:0!important;height:100%!important;width:100%!important}
                *{-ms-text-size-adjust:100%;-webkit-text-size-adjust:100%}.ExternalClass{width:100%}table,td{mso-table-lspace:0!important;mso-table-rspace:0!important}table{border-spacing:0!important;border-collapse:collapse!important;margin:0 auto!important}img{-ms-interpolation-mode:bicubic}a[x-apple-data-detectors]{color:inherit!important}
                @media screen and (max-width:600px){.email-container{width:100%!important}}
                </style>
            </head>
        <body bgcolor="#e0e0e0" width="100%" style="margin: 0; padding:0;">
        <div style="display:none;font-size:1px;line-height:1px;max-height:0px;max-width:0px;opacity:0;overflow:hidden;mso-hide:all;">' . strip_tags($intro) . '</div>
        <table bgcolor="#e0e0e0" cellpadding="0" cellspacing="0" border="0" height="100%" width="100%" style="border-collapse:collapse;">
          <tr>
            <td align="center">
              <table cellspacing="0" cellpadding="0" border="0" align="center" bgcolor="#f3f3f3" width="600" class="email-container" style="border-collapse:collapse;">
              <thead>
                   <tr>
                    ' . $headerOpen . $headerContent . '</td>
                   </tr>
                  </thead>
                <tr>
                  <td style="padding:24px 24px 28px 24px; font-family: Arial, Helvetica, sans-serif; font-size:13px; color:#333333; background:#ffffff;">
                    <p style="font-size:17px; font-weight:bold; margin:0 0 2px 0; color:#11224D;">&#9888;&#65039; ' . $title . '</p>
                    ' . ($instance !== '' ? '<p style="font-size:14px; font-weight:bold; margin:0 0 10px 0; color:#0459A1;">' . $esc($instance) . '</p>' : '') . '
                    <p style="font-size:13px; margin:0 0 18px 0; line-height:1.6; color:#555555;">' . $intro . '</p>

                    <table width="100%" cellpadding="0" cellspacing="0" border="0" style="background:#f4f6fa; border-radius:8px; margin:0 0 18px 0;">
                      <tr><td style="padding:12px 14px; font-family:Arial,Helvetica,sans-serif; font-size:12px; line-height:1.9; color:#555555;">
                        ' . implode('<br>', $info) . '
                      </td></tr>
                    </table>
                    ' . ($rows !== '' ? '<div style="height:4px; line-height:4px; font-size:1px;">&nbsp;</div>
                    <table width="100%" cellpadding="0" cellspacing="0" border="0" style="border:1px solid #e6e9f0; border-radius:8px;">
                      ' . $rows . '
                    </table>' : '') . '
                    ' . $link . '
                    <div style="clear:both; height:20px; line-height:20px; font-size:1px;">&nbsp;</div>
                    <p style="font-size:11px; margin:0; line-height:1.6; color:#999999;">
                      ' . $note . '
                    </p>
                  </td>
                </tr>
                  <tfoot>
                 <tr>
                    ' . $footerTag . '
                    </td>
                 </tr>
               </tfoot>
              </table>
            </td>
          </tr>
        </table>
      </body>';
    }

    if ($params["type"] == 14) { // SSL Renewal

      if (isset($banner)) {
        $headerOpen = '<td style="padding:20px; display:block; height:70px; background:url(\'https://' . $banner . ' \') center/cover no-repeat;">'; 
      } else {
        $headerOpen = '<td style="padding:20px; display:block; height:auto; background:url(\'https://cap.ringq.com/emailuse/RingQmailer2.jpg\') center/cover no-repeat;">'; 
      }

      $body = '
        <html>
          <head>
            <meta charset="UTF-8">
            <meta name="viewport" content="width=device-width,initial-scale=1">
            <meta http-equiv="X-UA-Compatible" content="IE=edge">
            <title>SSL Certificate Renewal Notification</title>
            <style type="text/css">
              body,html{margin:0!important;padding:0!important;height:100%!important;width:100%!important}
              *{-ms-text-size-adjust:100%;-webkit-text-size-adjust:100%}
              .ExternalClass{width:100%}
              div[style*="margin: 16px 0"]{margin:0!important}
              table,td{mso-table-lspace:0!important;mso-table-rspace:0!important}
              table{border-spacing:0!important;border-collapse:collapse!important;table-layout:fixed!important;margin:0 auto!important}
              table table table{table-layout:auto}
              img{-ms-interpolation-mode:bicubic}
              a[x-apple-data-detectors]{color:inherit!important}
              a:link{text-decoration:none;color:#7b00ff}
              a:visited{color:#87ceeb}
            </style>
            <style type="text/css">
              .button-a,.button-td{transition:all .1s ease-in}
              .button-a:hover,.button-td:hover{background:#0089cb!important;border-color:#0089cb!important}
              @media screen and (max-width:600px){
                .email-container{width:100%!important}
                .fluid,.fluid-centered{max-width:100%!important;height:auto!important;margin-left:auto!important;margin-right:auto!important}
                .stack-column,.stack-column-center{display:block!important;width:100%!important;max-width:100%!important;direction:ltr!important}
                .stack-column-center{text-align:center!important}
                .center-on-narrow{text-align:center!important;display:block!important;margin-left:auto!important;margin-right:auto!important;float:none!important}
                table.center-on-narrow{display:inline-block!important}
              }
            </style>
            <link href="https://cdn.usebootstrap.com/bootstrap/4.3.1/css/bootstrap.min.css" rel="stylesheet" type="text/css">
          </head>
          <body bgcolor="#e0e0e0" width="100%" style="margin: 0;" yahoo="yahoo">
            <table bgcolor="#e0e0e0" cellpadding="0" cellspacing="0" border="0" height="100%" width="100%" style="border-collapse:collapse;">
              <tr>
                <td>
                  <center style="width: 100%;">
                    <div style="display:none;font-size:1px;line-height:1px;max-height:0px;max-width:0px;opacity:0;overflow:hidden;mso-hide:all;font-family: sans-serif;">
                      Your SSL certificate has been successfully renewed.
                    </div>
                    <table cellspacing="0" cellpadding="0" border="0" align="center" bgcolor="#f3f3f3" width="600" class="email-container">
                         <thead>
                          <tr>
                            ' . $headerOpen . '</td>
                          </tr>
                          </thead>    
                      <tr>
                        <td style="padding: 20px 40px; background-color: #ffffff; text-align: justify; font-family: sans-serif; font-size: 15px; line-height: 22px; color: #555555;">
                          <h1 style="color:#2d2a4a;">SSL Certificate Renewal</h1>
                          <p>Dear Customer,</p>
                          <p>
                            We’re pleased to inform you that your SSL certificate for 
                            <strong>'. htmlspecialchars($params["fqdn"]) .'</strong> 
                            has been successfully <strong>renewed</strong>.
                          </p>
                          <p>
                            Your secure connection will continue to protect your users’ data and maintain trust in your website.
                          </p>
                          <p>
                            No further action is required on your part. You can verify the certificate status anytime from your 
                            <a href="https://www.ringq.com/login">'. $customName .' Dashboard</a>.
                          </p>
                          <br>
                          <p>Thank you for trusting '. $customName .' with your security needs.</p>
                          <p style="margin-bottom:0;">Best regards,<br><strong>The RingQ Team</strong></p>
                        </td>
                      </tr>
                      <tr>
                        <td bgcolor="#11224D" style="text-align: center; padding: 30px 40px; color: #ffffff; font-family: Segoe UI, Verdana, sans-serif;">
                          <p style="margin: 0; font-size: 18px;">'. $customName .' – Secure. Reliable. Connected.</p>
                        </td>
                      </tr>
                    </table>

                    <table align="center" width="600" class="email-container">
                      <tr>
                        <td bgcolor="#e0e0e0" style="padding: 20px; font-size: 12px; font-family: Arial; line-height:18px; text-align: center; color: #2d2a4a;">
                          If you did not request this renewal, please 
                          <a href="https://www.ringq.com/contact/">contact us</a> immediately.<br><br>
                          © 2025 '. $customName .'. All Rights Reserved.
                        </td>
                      </tr>
                    </table>
                  </center>
                </td>
              </tr>
            </table>
          </body>
        </html>';
    }

    if ($params["type"] == 15) { // Backup Success

      if (isset($banner)) {
        $headerOpen = '<td style="padding:20px; display:block; height:70px; background:url(\'https://' . $banner . ' \') center/cover no-repeat;">'; 
      } else {
        $headerOpen = '<td style="padding:20px; display:block; height:auto; background:url(\'https://cap.ringq.com/emailuse/RingQmailer2.jpg\') center/cover no-repeat;">'; 
      }

      $body = '
        <html>
          <head>
            <meta charset="UTF-8">
            <meta name="viewport" content="width=device-width,initial-scale=1">
            <meta http-equiv="X-UA-Compatible" content="IE=edge">
            <title>Backup Completion Notification</title>
            <style type="text/css">
              body,html{margin:0!important;padding:0!important;height:100%!important;width:100%!important}
              *{-ms-text-size-adjust:100%;-webkit-text-size-adjust:100%}
              .ExternalClass{width:100%}
              div[style*="margin: 16px 0"]{margin:0!important}
              table,td{mso-table-lspace:0!important;mso-table-rspace:0!important}
              table{border-spacing:0!important;border-collapse:collapse!important;table-layout:fixed!important;margin:0 auto!important}
              table table table{table-layout:auto}
              img{-ms-interpolation-mode:bicubic}
              a[x-apple-data-detectors]{color:inherit!important}
              a:link{text-decoration:none;color:#7b00ff}
              a:visited{color:#87ceeb}
            </style>
            <style type="text/css">
              .button-a,.button-td{transition:all .1s ease-in}
              .button-a:hover,.button-td:hover{background:#0089cb!important;border-color:#0089cb!important}
              @media screen and (max-width:600px){
                .email-container{width:100%!important}
                .fluid,.fluid-centered{max-width:100%!important;height:auto!important;margin-left:auto!important;margin-right:auto!important}
                .stack-column,.stack-column-center{display:block!important;width:100%!important;max-width:100%!important;direction:ltr!important}
                .stack-column-center{text-align:center!important}
                .center-on-narrow{text-align:center!important;display:block!important;margin-left:auto!important;margin-right:auto!important;float:none!important}
                table.center-on-narrow{display:inline-block!important}
              }
            </style>
            <link href="https://cdn.usebootstrap.com/bootstrap/4.3.1/css/bootstrap.min.css" rel="stylesheet" type="text/css">
          </head>
          <body bgcolor="#e0e0e0" width="100%" style="margin: 0;" yahoo="yahoo">
            <table bgcolor="#e0e0e0" cellpadding="0" cellspacing="0" border="0" height="100%" width="100%" style="border-collapse:collapse;">
              <tr>
                <td>
                  <center style="width: 100%;">
                    <div style="display:none;font-size:1px;line-height:1px;max-height:0px;max-width:0px;opacity:0;overflow:hidden;mso-hide:all;font-family: sans-serif;">
                      Your scheduled backup has been successfully completed.
                    </div>
                    <table cellspacing="0" cellpadding="0" border="0" align="center" bgcolor="#f3f3f3" width="600" class="email-container">
                        <thead>
                          <tr>
                            ' . $headerOpen . '</td>
                          </tr>
                          </thead> 
                      <tr>
                        <td style="padding: 20px 40px; background-color: #ffffff; text-align: justify; font-family: sans-serif; font-size: 15px; line-height: 22px; color: #555555;">
                          <h1 style="color:#2d2a4a;">Backup Success Notification</h1>
                          <p>Dear Administrator,</p>
                          <p>
                            We are pleased to inform you that your system backup for 
                            <strong>'. htmlspecialchars($params["hostname"]) .'</strong> 
                            has been successfully <strong>completed</strong>.
                          </p>
                          <div style="background-color: #f9f9f9; padding: 15px; border-radius: 8px; margin: 20px 0;">
                            <p style="margin: 0; font-weight: bold; color: #333;">Backup Details:</p>
                            <ul style="margin: 10px 0 0 20px; padding: 0;">
                              <li><strong>Name:</strong> '. htmlspecialchars($params["backupName"]) .'</li>
                              <li><strong>Date:</strong> '. htmlspecialchars($params["date"]) .'</li>
                              <li><strong>Size:</strong> '. htmlspecialchars($params["size"]) .'</li>
                              <li><strong>Includes:</strong> '. htmlspecialchars($params["includes"]) .'</li>
                              <li><strong>Storage:</strong> '. htmlspecialchars($params["storage"]) .'</li>
                            </ul>
                          </div>
                          <p>
                            Your system configuration and data are safely stored according to your backup strategy.
                          </p>
                          <br>
                          <p>Best regards,<br><strong>The '. $customName .' Team</strong></p>
                        </td>
                      </tr>
                      <tr>
                        <td bgcolor="#11224D" style="text-align: center; padding: 30px 40px; color: #ffffff; font-family: Segoe UI, Verdana, sans-serif;">
                          <p style="margin: 0; font-size: 18px;">'. $customName .' – Secure. Reliable. Connected.</p>
                        </td>
                      </tr>
                    </table>

                    <table align="center" width="600" class="email-container">
                      <tr>
                        <td bgcolor="#e0e0e0" style="padding: 20px; font-size: 12px; font-family: Arial; line-height:18px; text-align: center; color: #2d2a4a;">
                          © 2025 '. $customName .'. All Rights Reserved.
                        </td>
                      </tr>
                    </table>
                  </center>
                </td>
              </tr>
            </table>
          </body>
        </html>';
    }

    if ($params["type"] == 16) { // Backup Failure
      if (isset($banner)) {
        $headerOpen = '<td style="padding:20px; display:block; height:70px; background:url(\'https://' . $banner . ' \') center/cover no-repeat;">'; 
      } else {
        $headerOpen = '<td style="padding:20px; display:block; height:auto; background:url(\'https://cap.ringq.com/emailuse/RingQmailer2.jpg\') center/cover no-repeat;">'; 
      }
      $body = '
        <html>
          <head>
            <meta charset="UTF-8">
            <meta name="viewport" content="width=device-width,initial-scale=1">
            <meta http-equiv="X-UA-Compatible" content="IE=edge">
            <title>Backup Failure Notification</title>
            <style type="text/css">
              body,html{margin:0!important;padding:0!important;height:100%!important;width:100%!important}
              *{-ms-text-size-adjust:100%;-webkit-text-size-adjust:100%}
              .ExternalClass{width:100%}
              div[style*="margin: 16px 0"]{margin:0!important}
              table,td{mso-table-lspace:0!important;mso-table-rspace:0!important}
              table{border-spacing:0!important;border-collapse:collapse!important;table-layout:fixed!important;margin:0 auto!important}
              table table table{table-layout:auto}
              img{-ms-interpolation-mode:bicubic}
              a[x-apple-data-detectors]{color:inherit!important}
              a:link{text-decoration:none;color:#ff4d4d}
              a:visited{color:#ff4d4d}
            </style>
            <style type="text/css">
              .button-a,.button-td{transition:all .1s ease-in}
              .button-a:hover,.button-td:hover{background:#cc0000!important;border-color:#cc0000!important}
              @media screen and (max-width:600px){
                .email-container{width:100%!important}
                .fluid,.fluid-centered{max-width:100%!important;height:auto!important;margin-left:auto!important;margin-right:auto!important}
                .stack-column,.stack-column-center{display:block!important;width:100%!important;max-width:100%!important;direction:ltr!important}
                .stack-column-center{text-align:center!important}
                .center-on-narrow{text-align:center!important;display:block!important;margin-left:auto!important;margin-right:auto!important;float:none!important}
                table.center-on-narrow{display:inline-block!important}
              }
            </style>
            <link href="https://cdn.usebootstrap.com/bootstrap/4.3.1/css/bootstrap.min.css" rel="stylesheet" type="text/css">
          </head>
          <body bgcolor="#e0e0e0" width="100%" style="margin: 0;" yahoo="yahoo">
            <table bgcolor="#e0e0e0" cellpadding="0" cellspacing="0" border="0" height="100%" width="100%" style="border-collapse:collapse;">
              <tr>
                <td>
                  <center style="width: 100%;">
                    <div style="display:none;font-size:1px;line-height:1px;max-height:0px;max-width:0px;opacity:0;overflow:hidden;mso-hide:all;font-family: sans-serif; color: #ff0000;">
                      CRITICAL: Your scheduled backup has failed.
                    </div>
                    <table cellspacing="0" cellpadding="0" border="0" align="center" bgcolor="#f3f3f3" width="600" class="email-container">
                     <thead>
                          <tr>
                            ' . $headerOpen . '</td>
                          </tr>
                          </thead>  
                      <tr>
                        <td style="padding: 20px 40px; background-color: #ffffff; text-align: justify; font-family: sans-serif; font-size: 15px; line-height: 22px; color: #555555;">
                          <h1 style="color:#d9534f;">Backup Failure Alert</h1>
                          <p>Dear Administrator,</p>
                          <p style="color: #d9534f; font-weight: bold;">
                            IMPORTANT: The scheduled backup for 
                            <strong>'. htmlspecialchars($params["hostname"]) .'</strong> 
                            has <strong>FAILED</strong> to complete.
                          </p>
                          <div style="background-color: #fff5f5; border: 1px solid #fab1a0; padding: 15px; border-radius: 8px; margin: 20px 0;">
                            <p style="margin: 0; font-weight: bold; color: #d63031;">Error Information:</p>
                            <p style="margin: 10px 0 0 0; font-family: monospace; color: #c0392b;">'. htmlspecialchars($params["errorMessage"]) .'</p>
                          </div>
                          <div style="background-color: #f9f9f9; padding: 15px; border-radius: 8px; margin: 20px 0;">
                            <p style="margin: 0; font-weight: bold; color: #333;">Attempt Details:</p>
                            <ul style="margin: 10px 0 0 20px; padding: 0;">
                              <li><strong>Name:</strong> '. htmlspecialchars($params["backupName"]) .'</li>
                              <li><strong>Date:</strong> '. htmlspecialchars($params["date"]) .'</li>
                              <li><strong>Storage Context:</strong> '. htmlspecialchars($params["storage"]) .'</li>
                            </ul>
                          </div>
                          <p>
                            Please log in to your <a href="https://www.ringq.com/login" style="color: #0984e3;">'. $customName .' Dashboard</a> to investigate the failure and ensure your data remains protected.
                          </p>
                          <br>
                          <p style="margin-bottom:0;">Best regards,<br><strong>The '. $customName .' Team</strong></p>
                        </td>
                      </tr>
                      <tr>
                        <td bgcolor="#11224D" style="text-align: center; padding: 30px 40px; color: #ffffff; font-family: Segoe UI, Verdana, sans-serif;">
                          <p style="margin: 0; font-size: 18px;">'. $customName .' – Secure. Reliable. Connected.</p>
                        </td>
                      </tr>
                    </table>

                    <table align="center" width="600" class="email-container">
                      <tr>
                        <td bgcolor="#e0e0e0" style="padding: 20px; font-size: 12px; font-family: Arial; line-height:18px; text-align: center; color: #2d2a4a;">
                          © 2025 '. $customName .'. All Rights Reserved.
                        </td>
                      </tr>
                    </table>
                  </center>
                </td>
              </tr>
            </table>
          </body>
        </html>';
    }

    if ($params["type"] == "webpassword") {
      if (isset($banner)) {
        $headerOpen = '<td style="padding:20px; display:block; height:70px; background:url(\'https://' . $banner . ' \') center/cover no-repeat;">'; 
        $headerContent = ''; // Hide logo/text if a custom banner is used
      } else {
        $headerOpen = '<td style="padding:20px; display:block; height:auto; background:url(\'https://cap.ringq.com/emailuse/WhatsApp-Image-2025-09-24-at-09.47.46.jpeg\') center/cover no-repeat;">'; 
        $headerContent = '
          <table width="100%" cellpadding="0" cellspacing="0" border="0">
              <tbody><tr>
                <td align="left">
                  <img src="https://cap.ringq.com/emailuse/RingQlogomailer-2.png" alt="RingQ Logo" width="130" height="65" style="display:block; max-width:130px; height:65px;">
                </td>
                <td align="right" valign="middle">
                  <p style="font-size: 16px; font-family: sans-serif; color: #ffffff; margin: 0; padding-right: 0px; font-weight: bold;">
                    Welcome to '. $customName .'!
                  </p>
                </td>
              </tr></tbody>
          </table>';
      }

      // --- Footer Logic ---
      $footerTag = '
          <td bgcolor="#11224D" valign="middle" style="text-align:center; padding:15px;">
                        <table align="center" border="0" cellpadding="0" cellspacing="0" width="100%">
                          <tr>
                            <td style="text-align:left; line-height:20px; padding:10px 30px; color:#8c94c4; font-weight:lighter; font-family: Arial, Helvetica, sans-serif; font-size:12px;">
                              This email was sent to 
                                <a href="mailto:'.$to.'" style="text-decoration: underline; color:rgb(0, 159, 227) !important;">
                                        <span style="color:rgb(0, 159, 227) !important;">'.$to.'</span>
                                    </a>.
                              Update your mailing preferences from your 
                              <span style="color:#216798;">Customer Portal</span> profile.
                            </td>
                          </tr>
    
                          <tr>
                            <td style="padding:10px 30px;">
                              <table width="100%" cellpadding="0" cellspacing="0" border="0">
                                <tr>
                                  <td align="left">
                                    <img src="https://cap.ringq.com/emailuse/RingQlogomailerfooter-2.png"
                                        alt="RingQ Logo"
                                        style="display:block; width:56.5px; height:27.01px;">
                                  </td>
                                  <td align="right">
                                    <a href="https://www.facebook.com/ringqcallcenter" style="margin-right:10px;">
                                      <img src="https://cap.ringq.com/emailuse/facebook-2.png" alt="Facebook" width="20" height="20">
                                    </a>
                                    <a href="https://www.linkedin.com/company/ringqcallcenter/" style="margin-right:10px;">
                                      <img src="https://cap.ringq.com/emailuse/linkedin-2.png" alt="LinkedIn" width="20" height="20">
                                    </a>
                                    <a href="https://www.youtube.com/@RingQ-ry6kw" style="margin-right:10px;">
                                      <img src="https://cap.ringq.com/emailuse/youtube-1.png" alt="YouTube" width="20" height="20">
                                    </a>
                                    <a href="https://x.com/RingQcloudcx" style="margin-right:10px;">
                                      <img src="https://cap.ringq.com/emailuse/twitter.png" alt="Twitter" width="20" height="20">
                                    </a>
                                    <a href="https://www.instagram.com/ringqcloudcx">
                                      <img src="https://cap.ringq.com/emailuse/instagram-1.png" alt="Instagram" width="20" height="20">
                                    </a>
                                  </td>
                                </tr>
                              </table>
                            </td>
                          </tr>
    
                          <tr>
                            <td align="center" style="color:#216798; font-size:10px; font-weight:lighter; font-family: Arial, Helvetica, sans-serif; padding-top:10px;">
                              Copyright ©️ 2026 '. $customName .'. All Rights Reserved.
                            </td>
                          </tr>
                        </table>
                      </td>'; 

      if (isset($footer)) {
        $footerTag = '<td style="padding:20px; display:block; height:70px; background:url(\'https://' . $footer . ' \') center/cover no-repeat;"></td>'; 
      }
      $body =
      '
      <html>
          <head>
            <meta charset="UTF-8"><meta name="viewport" content="width=device-width,initial-scale=1">
            <meta http-equiv="X-UA-Compatible" content="IE=edge">
            <title>Your '. $customName .' Verification Code</title>
            <style type="text/css">body,html{margin:0!important;padding:0!important;height:100%!important;width:100%!important}*{-ms-text-size-adjust:100%;-webkit-text-size-adjust:100%}.ExternalClass{width:100%}div[style*="margin: 16px 0"]{margin:0!important}table,td{mso-table-lspace:0!important;mso-table-rspace:0!important}table{border-spacing:0!important;border-collapse:collapse!important;table-layout:fixed!important;margin:0 auto}table table table{table-layout:auto}img{-ms-interpolation-mode:bicubic}.yshortcuts a{border-bottom:none!important}a[x-apple-data-detectors]{color:inherit!important}a:link{text-decoration:none;color:rgb(123, 0, 255)}a:visited{color:#87ceeb}
            </style>
            <style type="text/css">.button-a,.button-td{transition:all .1s ease-in}.button-a:hover,.button-td:hover{background:#0089cb!important;border-color:#0089cb!important}@media screen and (max-width:600px){.email-container{width:100%!important}.fluid,.fluid-centered{max-width:100%!important;height:auto!important;margin-left:auto!important;margin-right:auto!important}.fluid-centered{margin-left:auto!important;margin-right:auto!important}.stack-column,.stack-column-center{display:block!important;width:100%!important;max-width:100%!important;direction:ltr!important}.stack-column-center{text-align:center!important}.center-on-narrow{text-align:center!important;display:block!important;margin-left:auto!important;margin-right:auto!important;float:none!important}table.center-on-narrow{display:inline-block!important}}</style>
            <link href="https://cdn.usebootstrap.com/bootstrap/4.3.1/css/bootstrap.min.css" rel="stylesheet" type="text/css">
          </head>
          <body bgcolor="#e0e0e0" width="100%" style="margin:0" yahoo="yahoo">
            <table bgcolor="#e0e0e0" cellpadding="0" cellspacing="0" border="0" height="100%" width="100%" style="border-collapse:collapse; background:#f8f9fa;">
              <tr>
                <td><center style="width: 100%;">
                  <table cellspacing="0" cellpadding="0" border="0" align="center" bgcolor="#ffffff" width="600" class="email-container">
                       <thead>
                         <tr>
                          ' . $headerOpen . $headerContent . '</td>
                         </tr>
                        </thead>                     
                      <tbody>
                        <tr>
                          <td class="template-title-section" style="padding: 20px 40px 20px 40px; text-align: justify; font-family: sans-serif; font-size: 12px;  line-height: 20px; color: #555555; background-color: #ffffff">Hi, '. $params["firstName"] .' <br><br>
                          Here is your '. $customName .' web password for you to sign into your '. $customName .' account.
                          <p style="font-size:21px; margin: 20px 0px 0px 0px; color: #369ee3; font-weight:bold;">
                              <span style="color: #369ee3 !important;">' . $params["fqdn"] . '/cloudapp</span>
                            </p>
                          </td>
                        </tr>
                        
                  <tr>
                  <td style="background-color: #ffffff; padding-bottom: 20px">
                  <table width="90%" cellpadding="0" cellspacing="0" border="0" style="margin: 0px ; background-color: #e6eeff; border-radius: 16px;">
      
                        
                        <tr>
                          <td align="center" style="padding: 20px 40px; background-color: #e6eeff; border-radius:0px 20px 20px 0px;">
                        
                            <!-- Card Wrapper -->
                            <table width="100%" cellpadding="0" cellspacing="0" style="max-width:600px; background-color:#ffffff; border-radius:12px;">
                           <tr>
                            <td align="center" style="padding: 10px 0px 20px 30px; font-family:sans-serif;">
      
                              <!-- Added table with title and verification code -->
                              <table cellpadding="0" cellspacing="0" style="margin:0 auto; width: 100%; color:#555555; padding: 40px; border-radius:8px; width: 100%; padding:20px;">
                                <tr>
                                  <td align="center" style="font-family:sans-serif; width:100%">
      
                                    <!-- Title -->
                                    <p style="text-align: center; font-size:14px; color:#6b7280; margin:12px 0; font-weight:bold;">
                                      Web Password:
                                    </p>
      
                                    <table width="100%" border="0" cellspacing="0" cellpadding="0">
                                      <tr>
                                        <td align="center">
                                          <table border="0" cellspacing="0" width="100%" cellpadding="0">
                                            <tr>
                                              <td align="center" 
                                                  bgcolor="#1e3a8a" 
                                                  style="
                                                    background-color: #1e3a8a; 
                                                    background-image: linear-gradient(90deg, #1e3a8a, #38bdf8); 
                                                    border-radius:20px 0 0 20px;
                                                    padding: 12px 25px;
                                                  ">
                                                <span style="
                                                  color: #ffffff; 
                                                  font-family: Arial, sans-serif; 
                                                  font-size: 16px; 
                                                  font-weight: bold; 
                                                  text-decoration: none; 
                                                  letter-spacing: 2px;
                                                ">
                                                  ' . $params["password"] . '
                                                </span>
                                                </td>
                                            </tr>
                                          </table>
                                        </td>
                                      </tr>
                                    </table>
      
                                  <!-- Validity -->
                                  </td>
                                </tr>
                              </table>
                            </td>
                          </tr>
                            </table>
                                            <!-- Info -->
                              <p style="font-size:13px; color:#6b7280; margin:25px 0 0 0; line-height:20px;">
                                If you have any other questions or concerns,
                                please contact your system admin<br>
                                <span style="color:#318fd4; text-decoration: none">(' . $params["adminEmail"] . ') </span>
                              </p>
                              <br>
      
                              <!-- CTA Text -->
                              <p style="font-size:13px; color:#6b7280; margin:10px 0 20px 0;">
                                Click on this link to login automatically.
                              </p>
      
                              <!-- Button (table-based) -->
                              <table cellpadding="0" cellspacing="0" style="margin:0 auto;">
                                <tr>
                                  <td style="background-color:#369ee3; border-radius:8px; text-align:center;">
                                    <a
                                      href="https://' . $params["fqdn"] . '/cloudapp/?recover=' . $params["password"] . '&email=' . $to . '"
                                      style="
                                        display:inline-block;
                                        padding:8px 70px;
                                        font-size:12px;
                                        color:#ffffff;
                                        text-decoration:none;
                                      "
                                    >
                                      Login Now
                                    </a>
                                  </td>
                                </tr>
                              </table>
                          </td>
                        </tr>  
                      </table>  
                  </td>
              </tr>       
                        <tfoot>
                       <tr>
                          ' . $footerTag . '
                          </td>
                       </tr>
                     </tfoot>
                          <tr>
                      </tbody>
                    </table>
                  </center>
                </td>
              </tr>
            </table>
          </body>
        </html>';
    }
    
    if ($params["type"] == "createcsvlogs") {
        if (isset($banner)) {
        $headerOpen = '<td style="padding:20px; display:block; height:70px; background:url(\'https://' . $banner . ' \') center/cover no-repeat;">'; 
        $headerContent = ''; // Hide logo/text if a custom banner is used
      } else {
        $headerOpen = '<td style="padding:20px; display:block; height:auto; background:url(\'https://cap.ringq.com/emailuse/WhatsApp-Image-2025-09-24-at-09.47.46.jpeg\') center/cover no-repeat;">'; 
        $headerContent = '
          <table width="100%" cellpadding="0" cellspacing="0" border="0">
              <tbody><tr>
                <td align="left">
                  <img src="https://cap.ringq.com/emailuse/RingQlogomailer-2.png" alt="RingQ Logo" width="130" height="65" style="display:block; max-width:130px; height:65px;">
                </td>
              </tr></tbody>
          </table>';
      }

      // --- Footer Logic ---
      $footerTag = '
          <td bgcolor="#11224D" valign="middle" style="text-align:center; padding:15px;">
                        <table align="center" border="0" cellpadding="0" cellspacing="0" width="100%">
                          <tr>
                            <td style="text-align:left; line-height:20px; padding:10px 30px; color:#8c94c4; font-weight:lighter; font-family: Arial, Helvetica, sans-serif; font-size:12px;">
                              This email was sent to 
                                <a href="mailto:'.$to.'" style="text-decoration: underline; color:rgb(0, 159, 227) !important;">
                                        <span style="color:rgb(0, 159, 227) !important;">'.$to.'</span>
                                    </a>. 
                              Update your mailing preferences from your 
                              <span style="color:#216798;">Customer Portal</span> profile.
                            </td>
                          </tr>
    
                          <tr>
                            <td style="padding:10px 30px;">
                              <table width="100%" cellpadding="0" cellspacing="0" border="0">
                                <tr>
                                  <td align="left">
                                    <img src="https://cap.ringq.com/emailuse/RingQlogomailerfooter-2.png"
                                        alt="RingQ Logo"
                                        style="display:block; width:56.5px; height:27.01px;">
                                  </td>
                                  <td align="right">
                                    <a href="https://www.facebook.com/ringqcallcenter" style="margin-right:10px;">
                                      <img src="https://cap.ringq.com/emailuse/facebook-2.png" alt="Facebook" width="20" height="20">
                                    </a>
                                    <a href="https://www.linkedin.com/company/ringqcallcenter/" style="margin-right:10px;">
                                      <img src="https://cap.ringq.com/emailuse/linkedin-2.png" alt="LinkedIn" width="20" height="20">
                                    </a>
                                    <a href="https://www.youtube.com/@RingQ-ry6kw" style="margin-right:10px;">
                                      <img src="https://cap.ringq.com/emailuse/youtube-1.png" alt="YouTube" width="20" height="20">
                                    </a>
                                    <a href="https://x.com/RingQcloudcx" style="margin-right:10px;">
                                      <img src="https://cap.ringq.com/emailuse/twitter.png" alt="Twitter" width="20" height="20">
                                    </a>
                                    <a href="https://www.instagram.com/ringqcloudcx">
                                      <img src="https://cap.ringq.com/emailuse/instagram-1.png" alt="Instagram" width="20" height="20">
                                    </a>
                                  </td>
                                </tr>
                              </table>
                            </td>
                          </tr>
    
                          <tr>
                            <td align="center" style="color:#216798; font-size:10px; font-weight:lighter; font-family: Arial, Helvetica, sans-serif; padding-top:10px;">
                              Copyright ©️ 2026 '. $customName .' . All Rights Reserved.
                            </td>
                          </tr>
                        </table>
                      </td>'; 

      if (isset($footer)) {
        $footerTag = '<td style="padding:20px; display:block; height:70px; background:url(\'https://' . $footer . ' \') center/cover no-repeat;"></td>'; 
      }

      $body = '<head>
              <meta charset="UTF-8"><meta name="viewport" content="width=device-width,initial-scale=1">
                <meta http-equiv="X-UA-Compatible" content="IE=edge">
                <title>Your ' . $customName . '  Verification Code</title>
                <style type="text/css">
                body,
                html{
                    margin:0!important;padding:0!important;height:100%!important;width:100%!important
                }
                *{-ms-text-size-adjust:100%;-webkit-text-size-adjust:100%}.ExternalClass{width:100%}div[style*="margin: 16px 0"]{margin:0!important}table,td{mso-table-lspace:0!important;mso-table-rspace:0!important}table{border-spacing:0!important;border-collapse:collapse!important;table-layout:fixed!important;margin:0 auto!important}table table table{table-layout:auto}img{-ms-interpolation-mode:bicubic}.yshortcuts a{border-bottom:none!important}a[x-apple-data-detectors]{color:inherit!important}a:link{text-decoration:none;color: rgb(0, 159, 227);a:visited{color:#87ceeb}
                </style>
                <style type="text/css">.button-a,.button-td{transition:all .1s ease-in}.button-a:hover,.button-td:hover{background:#0089cb!important;border-color:#0089cb!important}
                @media screen and (max-width:600px){.email-container{width:100%!important}.fluid,.fluid-centered{max-width:100%!important;height:auto!important;margin-left:auto!important;margin-right:auto!important}.fluid-centered{margin-left:auto!important;margin-right:auto!important}.stack-column,.stack-column-center{display:block!important;width:100%!important;max-width:100%!important;direction:ltr!important}.stack-column-center{text-align:center!important}.center-on-narrow{text-align:center!important;display:block!important;margin-left:auto!important;margin-right:auto!important;float:none!important}table.center-on-narrow{display:inline-block!important}}</style>
                <link href="https://cdn.usebootstrap.com/bootstrap/4.3.1/css/bootstrap.min.css" rel="stylesheet" type="text/css">
            </head>
        <body bgcolor="#e0e0e0" width="100%" style="margin: 0; padding:0;" yahoo="yahoo">
        <table bgcolor="#e0e0e0" cellpadding="0" cellspacing="0" border="0" height="100%" width="100%" style="border-collapse:collapse;">
          <tr>
            <td align="center">
              <table cellspacing="0" cellpadding="0" border="0" align="center" bgcolor="#f3f3f3" width="600" class="email-container" style="border-collapse:collapse;">
                
                <!-- Header -->
              <thead>
                   <tr>
                    ' . $headerOpen . $headerContent . '</td>
                   </tr>
                  </thead>

                <!-- Greeting + Content -->
                <tr>
                  <td style="padding:20px; font-family: Arial, Helvetica, sans-serif; font-size:12px; color:#333333; background:#ffffff;">
                    <p style="font-size:12px; margin:0 0 15px 0;">Hi,</p>
                    <p style="font-size:12px; margin:0 0 20px 0; line-height:2;">
                      This is the <strong>downloaded CSV</strong> you requested from <strong>' . $params["hostname"] . '</strong>.
                      Please see the attachment below for the full details.
                    </p>
                    <p style="font-size:12px; margin:0 0 20px 0; line-height:1.6; color: #555;">
                      <strong>Source Details:</strong><br>
                      Channel: ' . (ucfirst($params["channel"]) ?: "N/A") . '<br>
                      Reference: ' . ($params["referencename"] ?: "N/A") . '
                    </p>
                    
                
                  

                   
                  </td>
                </tr>

                  <tfoot>
                 <tr>
                    ' . $footerTag . '
                    </td>
                 </tr>
               </tfoot>


              </table>
            </td>
          </tr>
        </table>
      </body>';      
    }

    if ($params["type"] == "scheduledreport") {
        if (isset($banner)) {
        $headerOpen = '<td style="padding:20px; display:block; height:70px; background:url(\'https://' . $banner . ' \') center/cover no-repeat;">'; 
        $headerContent = ''; // Hide logo/text if a custom banner is used
      } else {
        $headerOpen = '<td style="padding:20px; display:block; height:auto; background:url(\'https://cap.ringq.com/emailuse/WhatsApp-Image-2025-09-24-at-09.47.46.jpeg\') center/cover no-repeat;">'; 
        $headerContent = '
          <table width="100%" cellpadding="0" cellspacing="0" border="0">
              <tbody><tr>
                <td align="left">
                  <img src="https://cap.ringq.com/emailuse/RingQlogomailer-2.png" alt="RingQ Logo" width="130" height="65" style="display:block; max-width:130px; height:65px;">
                </td>
              </tr></tbody>
          </table>';
      }

      // --- Footer Logic ---
      $footerTag = '
          <td bgcolor="#11224D" valign="middle" style="text-align:center; padding:15px;">
                        <table align="center" border="0" cellpadding="0" cellspacing="0" width="100%">
                          <tr>
                            <td style="text-align:left; line-height:20px; padding:10px 30px; color:#8c94c4; font-weight:lighter; font-family: Arial, Helvetica, sans-serif; font-size:12px;">
                              This email was sent to 
                                <a href="mailto:'.$to.'" style="text-decoration: underline; color:rgb(0, 159, 227) !important;">
                                        <span style="color:rgb(0, 159, 227) !important;">'.$to.'</span>
                                    </a>. 
                              Update your mailing preferences from your 
                              <span style="color:#216798;">Customer Portal</span> profile.
                            </td>
                          </tr>
    
                          <tr>
                            <td style="padding:10px 30px;">
                              <table width="100%" cellpadding="0" cellspacing="0" border="0">
                                <tr>
                                  <td align="left">
                                    <img src="https://cap.ringq.com/emailuse/RingQlogomailerfooter-2.png"
                                        alt="RingQ Logo"
                                        style="display:block; width:56.5px; height:27.01px;">
                                  </td>
                                  <td align="right">
                                    <a href="https://www.facebook.com/ringqcallcenter" style="margin-right:10px;">
                                      <img src="https://cap.ringq.com/emailuse/facebook-2.png" alt="Facebook" width="20" height="20">
                                    </a>
                                    <a href="https://www.linkedin.com/company/ringqcallcenter/" style="margin-right:10px;">
                                      <img src="https://cap.ringq.com/emailuse/linkedin-2.png" alt="LinkedIn" width="20" height="20">
                                    </a>
                                    <a href="https://www.youtube.com/@RingQ-ry6kw" style="margin-right:10px;">
                                      <img src="https://cap.ringq.com/emailuse/youtube-1.png" alt="YouTube" width="20" height="20">
                                    </a>
                                    <a href="https://x.com/RingQcloudcx" style="margin-right:10px;">
                                      <img src="https://cap.ringq.com/emailuse/twitter.png" alt="Twitter" width="20" height="20">
                                    </a>
                                    <a href="https://www.instagram.com/ringqcloudcx">
                                      <img src="https://cap.ringq.com/emailuse/instagram-1.png" alt="Instagram" width="20" height="20">
                                    </a>
                                  </td>
                                </tr>
                              </table>
                            </td>
                          </tr>
    
                          <tr>
                            <td align="center" style="color:#216798; font-size:10px; font-weight:lighter; font-family: Arial, Helvetica, sans-serif; padding-top:10px;">
                              Copyright ©️ 2026 '. $customName .' . All Rights Reserved.
                            </td>
                          </tr>
                        </table>
                      </td>'; 

      if (isset($footer)) {
        $footerTag = '<td style="padding:20px; display:block; height:70px; background:url(\'https://' . $footer . ' \') center/cover no-repeat;"></td>'; 
      }

      // Scheduled report email. $params["reports"] = [[label, filename, rows], ...]
      $esc = function ($v) { return htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8'); };
      $srReports = isset($params["reports"]) && is_array($params["reports"]) ? $params["reports"] : [];
      $srRows = '';
      foreach ($srReports as $r) {
        $rows = (int) ($r["rows"] ?? 0);
        $rowsText = $rows > 0 ? number_format($rows) . ($rows === 1 ? ' row' : ' rows') : 'No records in this period';
        $rowsColor = $rows > 0 ? '#555555' : '#b54708';
        $srRows .= '
                      <tr>
                        <td style="padding:12px 14px; border-bottom:1px solid #e6e9f0; font-family:Arial,Helvetica,sans-serif;">
                          <div style="font-size:13px; font-weight:bold; color:#11224D;">' . $esc($r["label"] ?? '') . '</div>
                          <div style="font-size:11px; color:#888888; margin-top:3px; word-break:break-all;">&#128206; ' . $esc($r["filename"] ?? '') . '</div>
                        </td>
                        <td align="right" style="padding:12px 14px; border-bottom:1px solid #e6e9f0; font-family:Arial,Helvetica,sans-serif; font-size:12px; color:' . $rowsColor . '; white-space:nowrap;">' . $rowsText . '</td>
                      </tr>';
      }
      $srCount = count($srReports);
      // On-demand (Generate button in Reports) uses the same layout with its own wording.
      $srOnDemand = !empty($params["ondemand"]);
      if ($srOnDemand) {
        $srIntro = 'Here is the <strong>' . $esc($srReports[0]["label"] ?? 'report') . '</strong> report you generated from Reports. It is attached to this email as a CSV file.';
      } else {
        $srIntro = $srCount === 1
          ? 'Your scheduled <strong>' . $esc($srReports[0]["label"] ?? 'report') . '</strong> report is ready. It is attached to this email as a CSV file.'
          : 'Your scheduled reports are ready. ' . $srCount . ' CSV files are attached to this email.';
      }
      $srInfo = [];
      foreach ([["Period", "period"], ["Filters", "filters"], ["Generated", "generated"], ["Timezone", "timezone"], ["Schedule", "schedule"], ["Company", "hostname"]] as $f) {
        if (isset($params[$f[1]]) && trim((string) $params[$f[1]]) !== '') {
          $srInfo[] = '<strong style="color:#11224D;">' . $f[0] . ':</strong> ' . $esc($params[$f[1]]);
        }
      }
      $srNote = $srOnDemand
        ? 'You receive this because you clicked Generate in Reports on ' . $esc($params["hostname"] ?? '') . '.'
        : 'You receive this because you are a recipient of a scheduled report on ' . $esc($params["hostname"] ?? '') . '. To change or pause it, open Reports and choose Schedule report.';
      $srLink = !empty($params["link"])
        ? '<div style="height:22px; line-height:22px; font-size:1px;">&nbsp;</div><table cellpadding="0" cellspacing="0" border="0" align="left" style="margin:0 0 4px 0 !important;"><tr><td bgcolor="#0459A1" style="border-radius:6px;">
             <a href="' . $esc($params["link"]) . '" target="_blank" style="display:inline-block; padding:10px 22px; font-family:Arial,Helvetica,sans-serif; font-size:13px; font-weight:bold; color:#ffffff !important; text-decoration:none;"><span style="color:#ffffff !important;">Open Reports</span></a>
           </td></tr></table>'
        : '';

      $body = '<head>
              <meta charset="UTF-8"><meta name="viewport" content="width=device-width,initial-scale=1">
                <meta http-equiv="X-UA-Compatible" content="IE=edge">
                <title>' . $esc($subject) . '</title>
                <style type="text/css">
                body,html{margin:0!important;padding:0!important;height:100%!important;width:100%!important}
                *{-ms-text-size-adjust:100%;-webkit-text-size-adjust:100%}.ExternalClass{width:100%}table,td{mso-table-lspace:0!important;mso-table-rspace:0!important}table{border-spacing:0!important;border-collapse:collapse!important;margin:0 auto!important}img{-ms-interpolation-mode:bicubic}a[x-apple-data-detectors]{color:inherit!important}
                @media screen and (max-width:600px){.email-container{width:100%!important}}
                </style>
            </head>
        <body bgcolor="#e0e0e0" width="100%" style="margin: 0; padding:0;">
        <table bgcolor="#e0e0e0" cellpadding="0" cellspacing="0" border="0" height="100%" width="100%" style="border-collapse:collapse;">
          <tr>
            <td align="center">
              <table cellspacing="0" cellpadding="0" border="0" align="center" bgcolor="#f3f3f3" width="600" class="email-container" style="border-collapse:collapse;">
              <thead>
                   <tr>
                    ' . $headerOpen . $headerContent . '</td>
                   </tr>
                  </thead>
                <tr>
                  <td style="padding:24px 24px 28px 24px; font-family: Arial, Helvetica, sans-serif; font-size:13px; color:#333333; background:#ffffff;">
                    <p style="font-size:17px; font-weight:bold; margin:0 0 6px 0; color:#11224D;">' . $esc($params["title"] ?? 'Scheduled report') . '</p>
                    <p style="font-size:13px; margin:0 0 18px 0; line-height:1.6; color:#555555;">' . $srIntro . '</p>

                    <table width="100%" cellpadding="0" cellspacing="0" border="0" style="background:#f4f6fa; border-radius:8px; margin:0 0 18px 0;">
                      <tr><td style="padding:12px 14px; font-family:Arial,Helvetica,sans-serif; font-size:12px; line-height:1.9; color:#555555;">
                        ' . implode('<br>', $srInfo) . '
                      </td></tr>
                    </table>
                    <div style="height:16px; line-height:16px; font-size:1px;">&nbsp;</div>

                    <table width="100%" cellpadding="0" cellspacing="0" border="0" style="border:1px solid #e6e9f0; border-radius:8px;">
                      ' . $srRows . '
                    </table>
                    ' . $srLink . '
                    <div style="clear:both; height:20px; line-height:20px; font-size:1px;">&nbsp;</div>
                    <p style="font-size:11px; margin:0; line-height:1.6; color:#999999;">
                      ' . $srNote . '
                    </p>
                  </td>
                </tr>
                  <tfoot>
                 <tr>
                    ' . $footerTag . '
                    </td>
                 </tr>
               </tfoot>
              </table>
            </td>
          </tr>
        </table>
      </body>';
    }

    if ($params["type"] == "resolveoffline") {
      // --- Build the conversation thread from the flat 5-field records ---
      // Each message = [Data5(ts/id), Data4(avatarURL\name), Data3(senderID-role), Data2(ts), Data1(text)]
      // Alignment by role suffix: -Agent => left, -user => right, -status => centered.
      $conversation = $params["conversation"] ?? $params["data"] ?? [];
      if (!is_array($conversation)) { $conversation = []; }
      $messages = array_chunk($conversation, 5);
      $conversationThread = '';

      foreach ($messages as $msg) {
        $avatarAndName = $msg[1] ?? '';   // Data4
        $senderRole    = $msg[2] ?? '';   // Data3
        $timestampMs   = $msg[3] ?? '';   // Data2 (reliable timestamp)
        $text          = $msg[4] ?? '';   // Data1

        // Determine alignment from the role suffix
        $roleLower = strtolower((string) $senderRole);
        $side = 'left';
        if (substr($roleLower, -6) === '-agent') {
          $side = 'left';
        } elseif (substr($roleLower, -7) === '-status') {
          $side = 'center';
        } elseif (substr($roleLower, -5) === '-user') {
          $side = 'right';
        }

        // Display name from "avatarURL\Display Name" (avatar image intentionally not shown)
        $displayName = $avatarAndName;
        if (strpos($avatarAndName, '\\') !== false) {
          $pieces      = explode('\\', $avatarAndName, 2);
          $displayName = trim($pieces[1] ?? '');
        }

        // Format timestamp (auto-detect ms vs s)
        $tsRaw = preg_replace('/[^0-9]/', '', (string) $timestampMs);
        $ts    = ($tsRaw === '') ? 0 : (int) substr($tsRaw, 0, 13);
        if ($ts > 9999999999) { $ts = (int) ($ts / 1000); }
        $timeStr = $ts > 0 ? date('M d, Y h:i A', $ts) : '';

        $safeText = nl2br(htmlspecialchars((string) $text, ENT_QUOTES, 'UTF-8'));
        $safeName = htmlspecialchars((string) $displayName, ENT_QUOTES, 'UTF-8');

        if ($side === 'center') {
          // status / system line, centered. Extra top+bottom margin so consecutive
          // status lines don't crowd each other (email clients don't collapse these).
          $conversationThread .= '
            <table width="100%" cellpadding="0" cellspacing="0" border="0" style="margin:14px 0 14px 0;">
              <tr><td align="center" style="padding:6px 0;">
                <span style="display:inline-block; background:#e3e6ee; color:#666666; font-size:11px; padding:5px 14px; border-radius:12px; font-family:Arial,Helvetica,sans-serif;">'
                . $safeText . ($timeStr ? ' &middot; ' . $timeStr : '') .
                '</span>
              </td></tr>
            </table>';
        } else {
          $isLeft       = ($side === 'left');
          $align        = $isLeft ? 'left' : 'right';
          $bubbleBg     = $isLeft ? '#ffffff' : '#0459A1';
          $bubbleColor  = $isLeft ? '#333333' : '#ffffff';
          $bubbleBorder = $isLeft ? '#e6e6e6' : '#0459A1';
          $borderRadius = $isLeft ? '4px 16px 16px 16px' : '16px 4px 16px 16px';
          $nameMargin   = $isLeft ? '0 0 4px 2px' : '0 2px 4px 0';
          $timeMargin   = $isLeft ? '4px 0 0 2px' : '4px 2px 0 0';

          // If the message text is an uploaded file (chat_file_upload/...), show the
          // picture (or a file link) instead of the raw path. Mirrors the app logic.
          $bubbleContent = $safeText;
          if (preg_match('#^(chat_file_upload/.+?\.(png|jpe?g|gif|pdf|docx?|xlsx?|txt|zip|mp4))#i', (string) $text, $fm)) {
            $cleanPath = $fm[1];
            $ext       = strtolower(pathinfo($cleanPath, PATHINFO_EXTENSION));
            $host      = $params["hostname"] ?? '';
            $chan      = strtolower((string) ($params["channel"] ?? ''));
            $fileUrl   = ($chan === 'whatsapp')
              ? 'https://' . $host . '/' . $text
              : 'https://' . $host . '/' . $cleanPath;
            // Filename: website => after last '-'; otherwise last path segment, after last '-'.
            if ($chan === 'website') {
              $fp = explode('-', $text);
            } else {
              $seg = explode('/', $text); $fp = explode('-', end($seg));
            }
            $fileName  = end($fp);
            $safeUrl   = htmlspecialchars($fileUrl, ENT_QUOTES, 'UTF-8');
            $safeFile  = htmlspecialchars((string) $fileName, ENT_QUOTES, 'UTF-8');
            if (in_array($ext, ['png', 'jpg', 'jpeg', 'gif'], true)) {
              $bubbleContent = '<a href="' . $safeUrl . '" target="_blank" style="text-decoration:none;"><img src="' . $safeUrl . '" alt="' . $safeFile . '" style="display:block; max-width:220px; width:100%; height:auto; border-radius:8px;"></a>';
            } else {
              $bubbleContent = '&#128206; <a href="' . $safeUrl . '" target="_blank" style="color:inherit; text-decoration:underline; word-break:break-all;">' . $safeFile . '</a>';
            }
          }

          // Bubble column (70%) + empty spacer column (30%) force the bubble to its side
          $bubbleCell = '
                <td width="70%" align="' . $align . '" valign="top" style="text-align:' . $align . ';">
                  <div style="font-size:11px; color:#888888; margin:' . $nameMargin . '; font-family:Arial,Helvetica,sans-serif;">' . $safeName . '</div>
                  <div style="display:inline-block; max-width:100%; background:' . $bubbleBg . '; color:' . $bubbleColor . '; border:1px solid ' . $bubbleBorder . '; padding:10px 14px; border-radius:' . $borderRadius . '; font-size:13px; line-height:1.5; font-family:Arial,Helvetica,sans-serif; text-align:left; word-break:break-word;">' . $bubbleContent . '</div>
                  <div style="font-size:10px; color:#aaaaaa; margin:' . $timeMargin . '; font-family:Arial,Helvetica,sans-serif;">' . $timeStr . '</div>
                </td>';
          $spacerCell = '<td width="30%">&nbsp;</td>';

          $conversationThread .= '
            <table width="100%" cellpadding="0" cellspacing="0" border="0" style="margin:0 0 18px 0;">
              <tr>'
              . ($isLeft ? $bubbleCell . $spacerCell : $spacerCell . $bubbleCell) . '
              </tr>
            </table>';
        }
      }

        if (isset($banner)) {
        $headerOpen = '<td style="padding:20px; display:block; height:70px; background:url(\'https://' . $banner . ' \') center/cover no-repeat;">'; 
        $headerContent = ''; // Hide logo/text if a custom banner is used
      } else {
        $headerOpen = '<td style="padding:20px; display:block; height:auto; background:url(\'https://www.ringq.com/wp-content/uploads/2025/09/WhatsApp-Image-2025-09-24-at-09.47.46.jpeg\') center/cover no-repeat;">'; 
        $headerContent = '
          <table width="100%" cellpadding="0" cellspacing="0" border="0">
              <tbody><tr>
                <td align="left">
                  <img src="https://www.ringq.com/wp-content/uploads/2025/06/RingQlogomailer-2.png" alt="RingQ Logo" width="130" height="65" style="display:block; max-width:130px; height:65px;">
                </td>
              </tr></tbody>
          </table>';
      }

      // --- Footer Logic ---
      $footerTag = '
          <td bgcolor="#11224D" valign="middle" style="text-align:center; padding:15px;">
                        <table align="center" border="0" cellpadding="0" cellspacing="0" width="100%">
                          <tr>
                            <td style="text-align:left; line-height:20px; padding:10px 30px; color:#8c94c4; font-weight:lighter; font-family: Arial, Helvetica, sans-serif; font-size:12px;">
                              This email was sent to 
                                <a href="mailto:'.$to.'" style="text-decoration: underline; color:rgb(0, 159, 227) !important;">
                                        <span style="color:rgb(0, 159, 227) !important;">'.$to.'</span>
                                    </a>. 
                              Update your mailing preferences from your 
                              <span style="color:#216798;">Customer Portal</span> profile.
                            </td>
                          </tr>
    
                          <tr>
                            <td style="padding:10px 30px;">
                              <table width="100%" cellpadding="0" cellspacing="0" border="0">
                                <tr>
                                  <td align="left">
                                    <img src="https://www.ringq.com/wp-content/uploads/2025/06/RingQlogomailerfooter-2.png"
                                        alt="RingQ Logo"
                                        style="display:block; width:56.5px; height:27.01px;">
                                  </td>
                                  <td align="right">
                                    <a href="https://www.facebook.com/ringqcallcenter" style="margin-right:10px;">
                                      <img src="https://www.ringq.com/wp-content/uploads/2025/06/facebook-2.png" alt="Facebook" width="20" height="20">
                                    </a>
                                    <a href="https://www.linkedin.com/company/ringqcallcenter/" style="margin-right:10px;">
                                      <img src="https://www.ringq.com/wp-content/uploads/2025/06/linkedin-2.png" alt="LinkedIn" width="20" height="20">
                                    </a>
                                    <a href="https://www.youtube.com/@RingQ-ry6kw" style="margin-right:10px;">
                                      <img src="https://www.ringq.com/wp-content/uploads/2025/06/youtube-1.png" alt="YouTube" width="20" height="20">
                                    </a>
                                    <a href="https://x.com/RingQcloudcx" style="margin-right:10px;">
                                      <img src="https://www.ringq.com/wp-content/uploads/2025/09/twitter.png" alt="Twitter" width="20" height="20">
                                    </a>
                                    <a href="https://www.instagram.com/ringqcloudcx">
                                      <img src="https://www.ringq.com/wp-content/uploads/2025/09/instagram-1.png" alt="Instagram" width="20" height="20">
                                    </a>
                                  </td>
                                </tr>
                              </table>
                            </td>
                          </tr>
    
                          <tr>
                            <td align="center" style="color:#216798; font-size:10px; font-weight:lighter; font-family: Arial, Helvetica, sans-serif; padding-top:10px;">
                              Copyright ©️ 2026 '. $customName .' . All Rights Reserved.
                            </td>
                          </tr>
                        </table>
                      </td>'; 

      if (isset($footer)) {
        $footerTag = '<td style="padding:20px; display:block; height:70px; background:url(\'https://' . $footer . ' \') center/cover no-repeat;"></td>'; 
      }

      $body = '<head>
              <meta charset="UTF-8"><meta name="viewport" content="width=device-width,initial-scale=1">
                <meta http-equiv="X-UA-Compatible" content="IE=edge">
                <title>Your ' . $customName . '  Verification Code</title>
                <style type="text/css">
                body,
                html{
                    margin:0!important;padding:0!important;height:100%!important;width:100%!important
                }
                *{-ms-text-size-adjust:100%;-webkit-text-size-adjust:100%}.ExternalClass{width:100%}div[style*="margin: 16px 0"]{margin:0!important}table,td{mso-table-lspace:0!important;mso-table-rspace:0!important}table{border-spacing:0!important;border-collapse:collapse!important;table-layout:fixed!important;margin:0 auto!important}table table table{table-layout:auto}img{-ms-interpolation-mode:bicubic}.yshortcuts a{border-bottom:none!important}a[x-apple-data-detectors]{color:inherit!important}a:link{text-decoration:none;color: rgb(0, 159, 227);a:visited{color:#87ceeb}
                </style>
                <style type="text/css">.button-a,.button-td{transition:all .1s ease-in}.button-a:hover,.button-td:hover{background:#0089cb!important;border-color:#0089cb!important}
                @media screen and (max-width:600px){.email-container{width:100%!important}.fluid,.fluid-centered{max-width:100%!important;height:auto!important;margin-left:auto!important;margin-right:auto!important}.fluid-centered{margin-left:auto!important;margin-right:auto!important}.stack-column,.stack-column-center{display:block!important;width:100%!important;max-width:100%!important;direction:ltr!important}.stack-column-center{text-align:center!important}.center-on-narrow{text-align:center!important;display:block!important;margin-left:auto!important;margin-right:auto!important;float:none!important}table.center-on-narrow{display:inline-block!important}}</style>
                <link href="https://cdn.usebootstrap.com/bootstrap/4.3.1/css/bootstrap.min.css" rel="stylesheet" type="text/css">
            </head>
        <body bgcolor="#e0e0e0" width="100%" style="margin: 0; padding:0;" yahoo="yahoo">
        <table bgcolor="#e0e0e0" cellpadding="0" cellspacing="0" border="0" height="100%" width="100%" style="border-collapse:collapse;">
          <tr>
            <td align="center">
              <table cellspacing="0" cellpadding="0" border="0" align="center" bgcolor="#f3f3f3" width="600" class="email-container" style="border-collapse:collapse;">
                
                <!-- Header -->
              <thead>
                   <tr>
                    ' . $headerOpen . $headerContent . '</td>
                   </tr>
                  </thead>

                <!-- Greeting + Content -->
                <tr>
                  <td style="padding:20px; font-family: Arial, Helvetica, sans-serif; font-size:12px; color:#333333; background:#ffffff;">
                    <p style="font-size:15px; font-weight:bold; margin:0 0 6px 0; color:#11224D;">Conversation Transcript</p>
                    <p style="font-size:12px; margin:0 0 4px 0; color:#555555;">
                      Host: <strong>' . ($params["hostname"] ?? '') . '</strong>
                    </p>
                    <p style="font-size:12px; margin:0 0 16px 0; color:#555555;">
                      Channel: ' . (ucfirst($params["channel"] ?? '') ?: "N/A") . ' &nbsp;|&nbsp; Reference: ' . (($params["referencename"] ?? '') ?: "N/A") . '
                    </p>
                    <p style="font-size:12px; margin:0 0 16px 0; color:#555555;">
                      Status: ' . (ucfirst($params["status"] ?? '') ?: "N/A") . '
                    </p>

                    <!-- Conversation thread -->
                    <table width="100%" cellpadding="0" cellspacing="0" border="0" style="background:#f4f6fa; border-radius:12px;">
                      <tr>
                        <td style="padding:14px;">
                          ' . ($conversationThread ?: '<p style="text-align:center; color:#999999; font-size:12px; margin:10px 0;">No messages found in this conversation.</p>') . '
                        </td>
                      </tr>
                    </table>
                    
                
                  

                   
                  </td>
                </tr>

                  <tfoot>
                 <tr>
                    ' . $footerTag . '
                    </td>
                 </tr>
               </tfoot>


              </table>
            </td>
          </tr>
        </table>
      </body>';      
    }
    
    if ($params["type"] == 'custom') {
      $body = $params["body"];
    }
    if ($customSMTP) {
      $message->setBody($body, 'text/html');
    }
    $emailData['emailData']["content"] = [
      [
        "type" => "text/html",
        "value" => $body
      ]
    ];

    if ($params["type"] == 5) {
      $attachment = new \Swift_Attachment($params["file"]);
      $attachment->setFilename(basename($params["file"]));
      $attachment->setContentType('audio/mpeg');
      if ($customSMTP) { $message->attach($attachment); }
      $emailData['emailData']["attachments"] = [
        [
          "content" => base64_encode(file_get_contents($params["file"])),
          "type" => "audio/mpeg",
          "filename" => basename($params["file"]),
          "disposition" => "attachment"
        ]
      ];
    }



    if ($params["type"] == 11) {
      $attachment = new \Swift_Attachment($params["file"]);
      $attachment->setFilename(basename($params["file"]));
      $attachment->setContentType('audio/mpeg');
      if ($customSMTP) { $message->attach($attachment); }
      $emailData['emailData']["attachments"] = [
        [
          "content" => base64_encode(file_get_contents($params["file"])),
          "type" => "audio/mpeg",
          "filename" => basename($params["file"]),
          "disposition" => "attachment"
        ]
      ];
    }
    if ($params["type"] == 6) {
      $attachment = new \Swift_Attachment(
          $params["filebytes"],
          'invite.ics',
          'text/calendar'
      );
      $attachment->setContentType('text/calendar; charset=UTF-8; method=REQUEST');
      if ($customSMTP) { $message->attach($attachment); }
      $emailData['emailData']["attachments"] = [
        [
          "content" => base64_encode($params["filebytes"]),
          "type" => "text/calendar; method=REQUEST; name=\"invite.ics\"",
          "filename" => "invite.ics",
          "disposition" => "attachment"
        ]
      ];
    }
    if ($params["type"] == 7 || $params["type"] == "downloadcsvconvo") {
      $attachment = \Swift_Attachment::fromPath($params["file"]);
      $attachment->setFilename(basename($params["file"]));
      $attachment->setContentType('text/csv');
      if ($customSMTP) { $message->attach($attachment); }
      $emailData['emailData']["attachments"] = [
        [
          "content" => base64_encode(file_get_contents($params["file"])),
          "type" => "text/csv",
          "filename" => basename($params["file"]),
          "disposition" => "attachment"
        ]
      ];
      
      $emailData['emailData']["content"] = [
        [
          "type" => "text/plain",
          "value" => "Please see the attached file."
        ]
        
      ];
      if($params["type"] == "downloadcsvconvo"){
        $emailData['emailData']["content"] = [
          [
            "type" => "text/html",
            "value" => $body
          ]
        ];
      }
        
    }
    if ($params["type"] == "scheduledreport") {
      // One or more CSVs, each sent under its readable name instead of the cache uuid.
      $emailData['emailData']["attachments"] = [];
      foreach (($params["files"] ?? []) as $f) {
        if (empty($f["path"]) || !is_file($f["path"])) {
          continue;
        }
        $fname = !empty($f["filename"]) ? $f["filename"] : basename($f["path"]);
        if ($customSMTP) {
          $attachment = \Swift_Attachment::fromPath($f["path"]);
          $attachment->setFilename($fname);
          $attachment->setContentType('text/csv');
          $message->attach($attachment);
        }
        $emailData['emailData']["attachments"][] = [
          "content" => base64_encode(file_get_contents($f["path"])),
          "type" => "text/csv",
          "filename" => $fname,
          "disposition" => "attachment"
        ];
      }
    }
    if ($params["type"] == 8 || ($params["type"] == 'custom' && isset($params["filebytes"]))) {
      $attachment = new \Swift_Attachment(
          $params["filebytes"],
          'invite.ics',
          'text/calendar'
      );
      $attachment->setContentType('text/calendar; charset=UTF-8; method=REQUEST');
      if ($customSMTP) { $message->attach($attachment); }
      $emailData['emailData']["attachments"] = [
        [
          "content" => base64_encode($params["filebytes"]),
          "type" => "text/calendar; method=REQUEST; name=\"invite.ics\"",
          "filename" => "invite.ics",
          "disposition" => "attachment"
        ]
      ];
    }
    if ($customSMTP) {
      $result = $mailer->send($message);
      return [
        "success" => $result > 0,
        "accepted_recipients" => $result,
      ];
    } else {
      $emailData["licenseKey"] = $licenseConfig['data']['licenseKey'];
      $emailData['emailData']["which"] = 2;

      $url = "https://licensekey.ringq.com/process/email";

      $ch = curl_init();
      curl_setopt($ch, CURLOPT_URL, $url);
      curl_setopt($ch, CURLOPT_POST, true);
      curl_setopt($ch, CURLOPT_HTTPHEADER, [ 
        "Content-Type: application/json"
      ]);
      curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($emailData));
      curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);

      $response = curl_exec($ch);
      $httpcode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
      $curlError = curl_error($ch);

      curl_close($ch);

      if ($response === false) {
        return [
          "success" => false,
          "http_code" => 0,
          "errors" => ["cURL Error: $curlError"]
        ];
      }

      $decoded = json_decode($response, true);
      // The RingQ relay wraps a successful send in JSON under HTTP 200.
      if ($httpcode == 202 || ($httpcode == 200 && is_array($decoded) && ($decoded["result"] ?? null) === "success")) {
        return [
          "success" => true,
          "http_code" => $httpcode,
          "message" => "Email request accepted by relay"
        ];
      } else {
        $errors = [];

        if (isset($decoded["errors"])) {
          foreach ($decoded["errors"] as $err) {
            $errors[] = [
              "message" => $err["message"] ?? "Unknown error",
              "field"   => $err["field"] ?? null,
              "help"    => $err["help"] ?? null
            ]; 
          }
        } else {
          $errors[] = ["message" => $response];
        }

        return [
          "success" => false,
          "http_code" => $httpcode,
          "errors" => $errors
        ];
      }

    }
  }

  // ===========================================================================
  // -----pm code----- START — everything below this line is new. Nothing
  // above this comment (the whole send() method and everything else in the
  // class) has been touched or modified.
  // ===========================================================================
  //
  // Fax-received notification email. Fully self-contained — it does NOT
  // call send() and does NOT add a new $params["type"] branch inside
  // send(), so no existing code path is affected in any way.
  //
  // It mirrors the exact same house style (banner/footer via redis,
  // customSMTP vs SendGrid-relay branching, from-address handling) as
  // send() does, just built specifically for a received fax + PDF
  // attachment.
  //
  // Usage:
  //   $mailer = new \Library\Email();
  //   $mailer->sendFaxReceivedEmail($to, [
  //       "from"      => $fromNumber,      // caller number
  //       "toId"      => $toNumber,        // dialed DID / extension
  //       "extension" => $extension,
  //       "date"      => date('M d, Y h:i A'),
  //       "pages"     => $pages,
  //       "file"      => $pdfPath,         // full path to the received fax PDF
  //   ], $licenseConfig, $customName);
  //
  // Dedicated log file for this method, separate from faxlog()'s echo
  // (which only goes wherever worker.php's stdout happens to be sent).
  // Override the path with the FAX_EMAIL_LOG_PATH env var if needed.
  // Always check this exact file to see whether a fax-received email
  // was actually attempted/sent/failed, independent of anything else.
  private function logFaxEmail($message)
  {
    $logFile = getenv('FAX_EMAIL_LOG_PATH') ?: '/var/log/ringq/fax_email.log';
    $dir = dirname($logFile);
    if (!is_dir($dir)) {
      @mkdir($dir, 0755, true);
    }
    $line = '[' . date('Y-m-d H:i:s') . '] ' . $message . PHP_EOL;
    @file_put_contents($logFile, $line, FILE_APPEND | LOCK_EX);
  }

  public function sendFaxReceivedEmail($to, $params, $licenseConfig, $customName = "RingQ")
  {
    $this->logFaxEmail("START sendFaxReceivedEmail — to={$to}, from=" . ($params["from"] ?? "?") . ", pages=" . ($params["pages"] ?? "?") . ", file=" . ($params["file"] ?? "?"));

    try {
    $fqdn = $licenseConfig['data']['fqdn'];
    $enabled = $this->redis->get("smtp:" . $fqdn . ":enabled");
    $customSMTP = $enabled == "1";
    $mailer = "";
    $message = "";
    $banner = $this->redis->get($fqdn . "-banner");
    $footer = $this->redis->get($fqdn . "-footer");

    if ($customName === null || $customName === '') {
      $customName = "RingQ";
    }

    $this->logFaxEmail("fqdn={$fqdn}, delivery path=" . ($customSMTP ? "custom SMTP" : "SendGrid relay"));

    $subject = "New Fax Received from " . ($params["from"] ?? "Unknown");

    if ($customSMTP) {
      if (isset($banner)) {
        $banner = $fqdn . ':8443/img/' . $banner;
      }
      if (isset($footer)) {
        $footer = $fqdn . ':8443/img/' . $footer;
      }
      $smtpSettings = $this->redis->hgetall("smtp:" . $fqdn . ":settings");
      $transport = $this->customSmtpTransport($smtpSettings);
      $mailer = new Mailer($transport);
      $message = new Message($subject);

      $fromEmail = $smtpSettings["smtpEmail"];
      $fromName  = $smtpSettings["smtpName"];
      $customName = $fromName;
      $message->setFrom([$fromEmail => $fromName]);
      $message->setTo($to);
    } else {
      $fromEmail = "noreply@ringq.com";
      $fromName  = $customName;
      $banner = null;
      $footer = null;
    }

    if ($fromEmail === null || $fromEmail === '') {
      $fromEmail = "noreply@ringq.com";
      $fromName = "RingQ";
    }

    $emailData = [];
    $emailData['emailData']['personalizations'] = [
      [
        "to" => [
          [
            "email" => $to,
          ]
        ],
        "subject" => $subject
      ]
    ];

    $emailData['emailData']['from'] = [
      "email" => $fromEmail,
      "name"  => "test"
    ];

    // --- Header logic (same visual style as the type=1 template in send()) ---
    if (isset($banner)) {
      $headerOpen = '<td style="padding:20px; display:block; height:70px; background:url(\'https://' . $banner . ' \') center/cover no-repeat;">';
      $headerContent = '';
    } else {
      $headerOpen = '<td style="padding:20px; display:block; height:auto; background:url(\'https://cap.ringq.com/emailuse/WhatsApp-Image-2025-09-24-at-09.47.46.jpeg\') center/cover no-repeat;">';
      $headerContent = '
          <table width="100%" cellpadding="0" cellspacing="0" border="0">
              <tbody><tr>
                <td align="left">
                  <img src="https://cap.ringq.com/emailuse/RingQlogomailer-2.png" alt="RingQ Logo" width="130" height="65" style="display:block; max-width:130px; height:65px;">
                </td>
              </tr></tbody>
          </table>';
    }

    // --- Footer logic ---
    $footerTag = '
        <td bgcolor="#11224D" valign="middle" style="text-align:center; padding:15px;">
                      <table align="center" border="0" cellpadding="0" cellspacing="0" width="100%">
                        <tr>
                          <td style="text-align:left; line-height:20px; padding:10px 30px; color:#8c94c4; font-weight:lighter; font-family: Arial, Helvetica, sans-serif; font-size:12px;">
                            This email was sent to
                              <a href="mailto:'.$to.'" style="text-decoration: underline; color:rgb(0, 159, 227) !important;">
                                      <span style="color:rgb(0, 159, 227) !important;">'.$to.'</span>
                                  </a>.
                            Update your mailing preferences from your
                            <span style="color:#216798;">Customer Portal</span> profile.
                          </td>
                        </tr>
                        <tr>
                          <td align="center" style="color:#216798; font-size:10px; font-weight:lighter; font-family: Arial, Helvetica, sans-serif; padding-top:10px;">
                            Copyright ©️ 2026 '. $customName .'. All Rights Reserved.
                          </td>
                        </tr>
                      </table>
                    </td>';

    if (isset($footer)) {
      $footerTag = '<td style="padding:20px; display:block; height:70px; background:url(\'https://' . $footer . ' \') center/cover no-repeat;"></td>';
    }

    // --- Body ---
    $body = '<head>
            <meta charset="UTF-8"><meta name="viewport" content="width=device-width,initial-scale=1">
              <meta http-equiv="X-UA-Compatible" content="IE=edge">
              <title>New Fax Received - '. $customName .'</title>
          </head>
      <body bgcolor="#e0e0e0" width="100%" style="margin: 0; padding:0;" yahoo="yahoo">
      <table bgcolor="#e0e0e0" cellpadding="0" cellspacing="0" border="0" height="100%" width="100%" style="border-collapse:collapse;">
        <tr>
          <td align="center">
            <table cellspacing="0" cellpadding="0" border="0" align="center" bgcolor="#f3f3f3" width="600" class="email-container" style="border-collapse:collapse;">
              <thead>
                 <tr>
                  ' . $headerOpen . $headerContent . '</td>
                 </tr>
                </thead>
              <tr>
                <td style="padding:20px; font-family: Arial, Helvetica, sans-serif; font-size:12px; color:#333333; background:#ffffff;">
                  <p style="font-size:14px; font-weight:bold; margin:0 0 15px 0; color:#11224D;">You have received a new fax</p>
                  <p style="font-size:12px; margin:0 0 20px 0; line-height:1.8;">
                    A new fax has arrived on your '. $customName .' extension. Details are below, and the fax document is attached as a PDF.
                  </p>
                  <table width="100%" cellpadding="0" cellspacing="0" border="0" style="margin:0 0 20px 0; background:#f0f7ff; border-radius:12px;">
                    <tr>
                      <td style="padding:16px 20px; font-family: Arial, Helvetica, sans-serif; font-size:12px; color:#333333; line-height:22px;">
                        <strong>From:</strong> ' . htmlspecialchars($params["from"] ?? "Unknown") . '<br>
                        <strong>To (extension):</strong> ' . htmlspecialchars($params["toId"] ?? ($params["extension"] ?? "N/A")) . '<br>
                        <strong>Received:</strong> ' . htmlspecialchars($params["date"] ?? "N/A") . '<br>
                        <strong>Pages:</strong> ' . htmlspecialchars((string)($params["pages"] ?? "N/A")) . '
                      </td>
                    </tr>
                  </table>
                  <p style="font-size:12px; color:#555; margin:10px 0;">
                    If you were not expecting this fax, you can safely disregard this email.
                  </p>
                </td>
              </tr>
                <tfoot>
               <tr>
                  ' . $footerTag . '
                  </td>
               </tr>
             </tfoot>
            </table>
          </td>
        </tr>
      </table>
    </body>';

    if ($customSMTP) {
      $message->setBody($body, 'text/html');
    }
    $emailData['emailData']["content"] = [
      [
        "type" => "text/html",
        "value" => $body
      ]
    ];

    // --- PDF attachment ---
    if (!empty($params["file"]) && is_file($params["file"])) {
      $attachment = \Swift_Attachment::fromPath($params["file"]);
      $attachment->setFilename(basename($params["file"]));
      $attachment->setContentType('application/pdf');
      if ($customSMTP) { $message->attach($attachment); }
      $emailData['emailData']["attachments"] = [
        [
          "content" => base64_encode(file_get_contents($params["file"])),
          "type" => "application/pdf",
          "filename" => basename($params["file"]),
          "disposition" => "attachment"
        ]
      ];
    }

    if ($customSMTP) {
      $mailer->send($message);
      $this->logFaxEmail("SUCCESS — sent via custom SMTP to {$to}");
      return [
        "success" => true,
        "message" => "Fax email sent via custom SMTP"
      ];
    } else {
      $emailData["licenseKey"] = $licenseConfig['data']['licenseKey'];
      $emailData['emailData']["which"] = 2;

      $url = "https://licensekey.ringq.com/process/email";

      // Log exactly what's being sent (minus the raw base64 body of the
      // attachment, which would blow up the log file) so a relay rejection
      // like an IP-not-whitelisted error can be cross-checked against the
      // actual request instead of guessed at.
      $attachmentInfo = 'none';
      if (!empty($emailData['emailData']['attachments'][0])) {
        $att = $emailData['emailData']['attachments'][0];
        $attachmentInfo = ($att['filename'] ?? '?') . ' (' . ($att['type'] ?? '?')
            . ', base64 len=' . strlen($att['content'] ?? '') . ')';
      }
      $this->logFaxEmail("REQUEST to relay — url={$url}, to={$to}, subject={$subject}, "
          . "from=" . $emailData['emailData']['from']['email'] . ", which=" . $emailData['emailData']['which']
          . ", licenseKey=" . (empty($emailData['licenseKey']) ? 'EMPTY' : substr($emailData['licenseKey'], 0, 6) . '...')
          . ", attachment=" . $attachmentInfo);

      $ch = curl_init();
      curl_setopt($ch, CURLOPT_URL, $url);
      curl_setopt($ch, CURLOPT_POST, true);
      curl_setopt($ch, CURLOPT_HTTPHEADER, [
        "Content-Type: application/json"
      ]);
      curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($emailData));
      curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);

      $response = curl_exec($ch);
      $httpcode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
      $curlError = curl_error($ch);

      curl_close($ch);

      $this->logFaxEmail("RESPONSE from relay — http_code={$httpcode}, body=" . ($response === false ? '(no response)' : $response));

      if ($response === false) {
        $this->logFaxEmail("FAILED — cURL error reaching relay for {$to}: {$curlError}");
        return [
          "success" => false,
          "http_code" => 0,
          "errors" => ["cURL Error: $curlError"]
        ];
      }

      if ($httpcode == 202) {
        $this->logFaxEmail("SUCCESS — accepted by SendGrid relay for {$to}");
        return [
          "success" => true,
          "http_code" => 202,
          "message" => "Fax email request accepted by SendGrid"
        ];
      } else {
        $decoded = json_decode($response, true);
        $errors = [];

        if (isset($decoded["errors"])) {
          foreach ($decoded["errors"] as $err) {
            $errors[] = [
              "message" => $err["message"] ?? "Unknown error",
              "field"   => $err["field"] ?? null,
              "help"    => $err["help"] ?? null
            ];
          }
        } else {
          $errors[] = ["message" => $response];
        }

        $this->logFaxEmail("FAILED — relay rejected send to {$to}, http_code={$httpcode}, errors=" . json_encode($errors));
        return [
          "success" => false,
          "http_code" => $httpcode,
          "errors" => $errors
        ];
      }
    }
    } catch (\Throwable $e) {
      $this->logFaxEmail("EXCEPTION for {$to}: " . get_class($e) . ": " . $e->getMessage());
      return [
        "success" => false,
        "errors" => ["Exception: " . $e->getMessage()]
      ];
    }
  }
  // -----pm code----- END
}
