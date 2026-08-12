<?php

namespace App\Filament\Support;

use App\Enums\DomainStatus;
use App\Models\Listing;
use App\Models\Site;
use App\Sites\Domains\DnsChecker;
use Filament\Forms;
use Filament\Forms\Components\Actions\Action as FormAction;
use Filament\Notifications\Notification;
use Illuminate\Support\HtmlString;

/**
 * The customer's own domain, and the instructions to send them.
 *
 * Admin only, deliberately. Pointing a domain at us is the one step in this
 * product that can take a business's existing email down if they get it wrong,
 * so it goes through somebody who can talk them through it rather than sitting
 * as a text field in a self-service panel.
 *
 * What the application does after this is only ever look DNS up. The
 * certificate and the nginx server block are issued by a reconciler running as
 * root on a timer, because a queue worker that can run certbot and write into
 * /etc/nginx is a web process with root — and the 2026-08-11 outage is what one
 * bad nginx file costs here. See DEPLOYMENT.md, "Custom domains".
 */
class EditCustomDomainAction
{
    public static function make(string $name = 'edit_domain'): FormAction
    {
        return FormAction::make($name)
            ->label('Own domain')
            ->icon('heroicon-o-link')
            ->color('gray')
            ->visible(fn (?Listing $record): bool => $record !== null && self::siteFor($record) !== null)
            ->modalHeading('Point the business\'s own domain here')
            ->modalDescription('Their subdomain keeps working either way — it is what old links and the '
                .'draft address point at. This adds their domain alongside it.')
            ->modalSubmitActionLabel('Save')
            ->fillForm(function (?Listing $record): array {
                $site = $record === null ? null : self::siteFor($record);

                return $site === null ? [] : ['custom_domain' => $site->custom_domain];
            })
            ->form([
                Forms\Components\TextInput::make('custom_domain')
                    ->label('Domain')
                    ->placeholder('example.com.na')
                    ->helperText('Without http:// and without www. Leave empty to remove it again.')
                    ->rule('regex:/^[a-z0-9]([a-z0-9-]*[a-z0-9])?(\.[a-z0-9]([a-z0-9-]*[a-z0-9])?)+$/i')
                    ->maxLength(255),

                Forms\Components\Placeholder::make('dns_instructions')
                    ->label('Send this to the business')
                    ->content(fn (?Listing $record): HtmlString => self::instructions($record)),

                Forms\Components\Placeholder::make('domain_state')
                    ->label('Where it has got to')
                    ->content(fn (?Listing $record): string => self::state($record)),
            ])
            ->action(function (?Listing $record, array $data): void {
                $site = $record === null ? null : self::siteFor($record);

                if ($site === null) {
                    return;
                }

                $domain = strtolower(trim((string) ($data['custom_domain'] ?? '')));
                $domain = preg_replace('~^https?://~', '', $domain) ?? $domain;
                $domain = preg_replace('~^www\.~', '', rtrim($domain, '/')) ?? $domain;

                // Changing the domain restarts the whole state machine: a new
                // name has different DNS and no certificate, and carrying the
                // old status over would have the reconciler believe it is done.
                $changed = $domain !== (string) $site->custom_domain;

                $site->forceFill([
                    'custom_domain' => $domain === '' ? null : $domain,
                    'domain_status' => $domain === ''
                        ? null
                        : ($changed ? DomainStatus::PendingDns : $site->domain_status),
                    'domain_checked_at' => $changed ? null : $site->domain_checked_at,
                    'domain_message' => $changed ? null : $site->domain_message,
                ])->save();

                Notification::make()
                    ->title($domain === '' ? 'Domain removed' : 'Saved — now waiting for their DNS')
                    ->body($domain === ''
                        ? 'The site stays reachable on its subdomain.'
                        : 'We check every five minutes. Nothing else to do here.')
                    ->success()
                    ->send();
            });
    }

    /**
     * The copy-and-paste part, written to be forwarded to somebody who has
     * never seen a DNS panel.
     */
    private static function instructions(?Listing $record): HtmlString
    {
        $site = $record === null ? null : self::siteFor($record);
        $ip = DnsChecker::serverIp();

        if ($ip === null) {
            return new HtmlString(
                '<p class="text-sm">Set <code>SITES_SERVER_IP</code> in the environment first — '
                .'without it there is no address to give anybody.</p>'
            );
        }

        $domain = $site?->custom_domain ?: 'example.com.na';

        $text = "To point {$domain} at your new website, add these two records at whoever you bought the "
            ."domain from (the DNS or \"Zone\" section):\n\n"
            ."    Type: A     Name: @      Value: {$ip}\n"
            ."    Type: A     Name: www    Value: {$ip}\n\n"
            ."If records of type A already exist for @ or www, change them rather than adding more.\n"
            ."Leave every other record alone — in particular anything of type MX, which is your email.\n\n"
            .'It can take a few hours to take effect. We check automatically and switch your site over '
            .'the moment it does; there is nothing else you need to do.';

        return new HtmlString(
            '<textarea readonly rows="12" class="w-full text-xs font-mono p-2 rounded border '
            .'bg-gray-50 dark:bg-gray-900 dark:border-gray-700" onclick="this.select()">'
            .e($text).'</textarea>'
        );
    }

    private static function state(?Listing $record): string
    {
        $site = $record === null ? null : self::siteFor($record);

        if ($site === null || blank($site->custom_domain)) {
            return 'No domain yet. The site is on its subdomain.';
        }

        $checked = $site->domain_checked_at?->diffForHumans();

        return match ($site->domain_status) {
            DomainStatus::Live => 'Live. The site answers on '.$site->custom_domain.'.',
            DomainStatus::DnsOk => 'DNS found. The certificate is being issued — usually within the hour.',
            DomainStatus::Failed => 'Needs attention: '.($site->domain_message ?: 'no reason recorded').'.',
            default => 'Waiting for their DNS'.($checked ? ' (last checked '.$checked.')' : '')
                .'. '.($site->domain_message ?: ''),
        };
    }

    private static function siteFor(Listing $listing): ?Site
    {
        return Site::where('source_listing_id', $listing->id)->first();
    }
}
