<?php
// Nuvis StudioX - Emailer (PHP 8.1+ compatible)

class NuEmailer {
    private $config;

    public function __construct() {
        global $nuConfig;
        $this->config = $nuConfig ?? [];
    }

    /**
     * Send a plain text or HTML email.
     * @return bool
     */
    public function send($to, $subject, $body, $isHtml = false) {
        $from     = $this->config['mailFrom']     ?? 'noreply@localhost';
        $fromName = $this->config['mailFromName'] ?? 'Nuvis StudioX';

        $contentType = $isHtml ? 'text/html' : 'text/plain';
        $headers  = "From: {$fromName} <{$from}>\r\n";
        $headers .= "Reply-To: {$from}\r\n";
        $headers .= "Content-Type: {$contentType}; charset=UTF-8\r\n";
        $headers .= "MIME-Version: 1.0\r\n";
        $headers .= "X-Mailer: NuvisStudioX\r\n";

        return mail($to, $subject, $body, $headers);
    }

    /**
     * Send a password reset email.
     * @return bool
     */
    public function sendPasswordReset($to, $resetToken, $username) {
        $appUrl   = $this->config['appUrl'] ?? '';
        $resetUrl = rtrim($appUrl, '/') . '/index.php?action=reset_password&token=' . urlencode($resetToken);
        $subject  = 'Password Reset Request';
        $body     = "Hello {$username},\n\n";
        $body    .= "You requested a password reset.\n\n";
        $body    .= "Click the link below to reset your password:\n";
        $body    .= $resetUrl . "\n\n";
        $body    .= "This link expires in 1 hour.\n\n";
        $body    .= "If you did not request this, ignore this email.\n";
        return $this->send($to, $subject, $body);
    }

    /**
     * Send a welcome email to a new user.
     * @return bool
     */
    public function sendWelcome($to, $username, $tempPassword = null) {
        $appUrl  = $this->config['appUrl'] ?? '';
        $subject = 'Welcome to Nuvis StudioX';
        $body    = "Hello {$username},\n\n";
        $body   .= "Your account has been created.\n\n";
        if ($tempPassword) {
            $body .= "Temporary password: {$tempPassword}\n";
            $body .= "Please change your password after logging in.\n\n";
        }
        $body .= "Login at: " . rtrim($appUrl, '/') . "/index.php\n";
        return $this->send($to, $subject, $body);
    }
}
