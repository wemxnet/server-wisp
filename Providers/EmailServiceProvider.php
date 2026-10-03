<?php

namespace Extensions\Servers\Wisp\Providers;

use Illuminate\Support\ServiceProvider;

class EmailServiceProvider extends ServiceProvider
{
    /**
     * Register the email templates of this extension so admins can edit them under Emails.
     */
    public function boot(): void
    {
        config(['email_events' => array_merge(config('email_events', []), [
            'server.wisp.account_created' => [
                'name' => 'WISP account created',
                'group' => 'Servers',
                'description' => 'Sent when a game panel account is created on WISP for an order.',
                'subject' => 'Game Panel Account Created',
                'body' => <<<'BODY'
Your account has been created on the game panel.
You can login using the following details:
Email: {{panel_email}}
Password: {{panel_password}}
BODY,
                'button_text' => 'Login to Game Panel',
                'placeholders' => [
                    'panel_email' => 'Panel login email',
                    'panel_password' => 'Panel login password',
                ],
            ],
        ])]);
    }
}
