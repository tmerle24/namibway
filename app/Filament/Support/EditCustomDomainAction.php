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
                    ->maxLength(255)
                    // So the records below name the domain being typed rather
                    // than an example of one.
                    ->live(onBlur: true),

                Forms\Components\Placeholder::make('dns_instructions')
                    ->label('Send this to the business')
                    ->content(fn (Forms\Get $get): HtmlString => self::instructions(
                        is_string($get('custom_domain')) ? $get('custom_domain') : null
                    )),

                Forms\Components\Placeholder::make('domain_state')
                    ->label('Where it has got to')
                    ->content(fn (?Listing $record): string => self::state($record)),
            ])
            ->action(function (?Listing $record, array $data): void {
                $site = $record === null ? null : self::siteFor($record);

                if ($site === null) {
                    return;
                }

                $domain = self::normalise((string) ($data['custom_domain'] ?? ''));

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
     * The two records, written the way a DNS panel asks for them.
     *
     * Always rendered, even with no address configured and no domain typed yet:
     * somebody opening this needs to see what they will be sending, and a
     * configuration note in place of the instructions is a screen that answers
     * a question nobody asked.
     */
    private static function instructions(?string $typed): HtmlString
    {
        $ip = self::advertisedIp();
        $domain = self::normalise((string) $typed) ?: 'yourdomain.com.na';

        $width = strlen($domain) + 8;

        $records = 'A    '.str_pad($domain, $width).($ip ?? '<your server address>')."\n"
            .'A    '.str_pad('www.'.$domain, $width).($ip ?? '<your server address>');

        return new HtmlString(
            '<p class="text-sm mb-2">Please add these entries to your DNS zone:</p>'
            .'<pre class="text-xs font-mono whitespace-pre overflow-x-auto p-3 rounded-lg border '
            .'bg-gray-50 border-gray-200 dark:bg-white/5 dark:border-white/10" '
            .'onclick="window.getSelection().selectAllChildren(this)">'.e($records).'</pre>'
            .'<p class="text-xs mt-2 opacity-70">If A records already exist for these names, change them '
            .'rather than adding more. Leave everything else alone — in particular MX records, which are '
            .'the email. It can take a few hours; we check automatically and switch the site over the '
            .'moment it works.</p>'
            .($ip === null
                ? '<p class="text-xs mt-2 text-danger-600 dark:text-danger-400">No server address is '
                    .'configured, so the value column is blank. Set <code>SITES_SERVER_IP</code> in the '
                    .'environment.</p>'
                : '')
        );
    }

    /**
     * The address to put in front of a customer.
     *
     * `SITES_SERVER_IP` when it is set, and otherwise whatever this
     * application's own hostname resolves to — which is this server, since
     * namibway.com's DNS points straight at it with nothing in between.
     *
     * Display only, and deliberately not shared with `DnsChecker::serverIp()`,
     * which decides whether a customer's domain has arrived. A guess is a fine
     * thing to show an admin who is about to read it and send it on; it is not
     * a thing to compare against, and putting a CDN's address behind a
     * certificate request would fail in front of the customer. Set the variable
     * and this is never reached.
     */
    private static function advertisedIp(): ?string
    {
        $configured = DnsChecker::serverIp();

        if ($configured !== null) {
            return $configured;
        }

        $host = parse_url((string) config('app.url'), PHP_URL_HOST);

        if (! is_string($host) || $host === '' || $host === 'localhost') {
            return null;
        }

        $records = @dns_get_record($host, DNS_A);

        if (! is_array($records)) {
            return null;
        }

        foreach ($records as $record) {
            if (isset($record['ip']) && is_string($record['ip'])) {
                return $record['ip'];
            }
        }

        return null;
    }

    /** The domain as it belongs in a zone: no scheme, no www, no trailing slash. */
    private static function normalise(string $domain): string
    {
        $domain = strtolower(trim($domain));
        $domain = preg_replace('~^https?://~', '', $domain) ?? $domain;
        $domain = rtrim($domain, '/');

        return preg_replace('~^www\.~', '', $domain) ?? $domain;
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
