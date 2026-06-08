<?php

declare(strict_types=1);

namespace Luster\Support;

use Luster\Core\TemplateEngine;

/**
 * Renders public-form success / action confirmation HTML.
 *
 * Used by all public form controllers (join, update, remove, reset, codes-submit,
 * contact, affiliate-apply, adopt, KIM) to produce the pre-rendered HTML stored in
 * the lstr_flash_success_html session key.
 *
 * When $template is empty the method returns a hardcoded default that reproduces the
 * pre-Phase-6 plain-text message for that action. When $template is set it is
 * processed via TemplateEngine with action-based conditionals and context tokens.
 *
 * Processing order: conditionals → flat tokens (no loops used).
 *
 * $context keys (all plain scalars — escaped automatically by TemplateEngine):
 *   fan_name, listing_title, listing_url   — generic success
 *   sender_name, sender_email,
 *   site_name, owner_name, owner_email     — contact-sent
 *   aff_name, aff_url, aff_email           — affiliate-apply-sent
 *
 * Conditions resolved from $action (key = action slug, '-' → '_'):
 *   joined, joined_pending, updated, updated_pending, removed,
 *   reset_sent, reset_done, code_submitted,
 *   kim_signed_up, kim_updated,
 *   adopt_submitted, contact_sent, affiliate_apply_sent
 *
 * Additional context-based conditions:
 *   aff_email    — true when $context['aff_email'] is non-empty
 *   no_aff_email — inverse
 */
final class SuccessRenderer
{
    /**
     * All recognised action slugs and their hardcoded default HTML.
     *
     * @var array<string, string>
     */
    private const DEFAULTS = [
        'joined'               => '<div class="fl-success"><p>Thank you for joining! Your membership is now live.</p></div>',
        'joined-pending'       => '<div class="fl-success"><p>Thank you for joining! Your membership may need to be approved before it appears in the member list.</p></div>',
        'updated'              => '<div class="fl-success"><p>Your membership has been updated successfully.</p></div>',
        'updated-pending'      => '<div class="fl-success"><p>Your update has been submitted and is awaiting admin review. Changes will appear once approved.</p></div>',
        'removed'              => '<div class="fl-success"><p>You have been removed from the listing. We\'re sorry to see you go!</p></div>',
        'reset-sent'           => '<div class="fl-success"><p>If that email address is registered, you will receive a password reset link shortly. The link expires in 1 hour.</p></div>',
        'reset-done'           => '<div class="fl-success"><p>Your password has been updated. You can now use it to update or remove your membership.</p></div>',
        'code-submitted'       => '<div class="fl-success"><p>Thank you for your code submission! It will appear after review.</p></div>',
        'kim-signed-up'        => '<div class="fl-success"><p>Thank you for signing up! Your entry is pending approval.</p></div>',
        'kim-updated'          => '<div class="fl-success"><p>Your KIM List entry has been updated successfully.</p></div>',
        'kim-reset-done'       => '<div class="fl-success"><p>Your KIM List password has been updated successfully.</p></div>',
        'adopt-submitted'      => '<div class="fl-success"><p>Thank you for your interest in adopting this fanlisting. Your application has been received.</p></div>',
        'contact-sent'         => '<div class="fl-success"><p>Your message has been sent. Thank you for getting in touch!</p></div>',
        'affiliate-apply-sent' => '<div class="fl-success"><p>Your application has been received. Thank you!</p></div>',
    ];

    /**
     * Renders success HTML for the given action.
     *
     * @param array<string, string> $context  plain-text token values — escaped by TemplateEngine
     */
    public static function render(
        string $template,
        string $action,
        array $context = [],
    ): string {
        if ($template === '') {
            return self::DEFAULTS[$action] ?? '<div class="fl-success"><p>Done.</p></div>';
        }

        // Build action-based conditions: 'joined-pending' → condition key 'joined_pending'
        $conditions = [];
        foreach (array_keys(self::DEFAULTS) as $knownAction) {
            $condKey               = str_replace('-', '_', $knownAction);
            $conditions[$condKey]  = ($action === $knownAction);
        }

        // Context-based conditions for affiliate-apply-sent
        $affEmail                    = (string) ($context['aff_email'] ?? '');
        $conditions['aff_email']     = ($affEmail !== '');
        $conditions['no_aff_email']  = ($affEmail === '');

        // Token map — all known keys pre-populated as empty strings so unset
        // context keys produce empty output rather than an unreplaced {token}
        $escaped = array_merge([
            'action'        => $action,
            'fan_name'      => '',
            'listing_title' => '',
            'listing_url'   => '',
            'sender_name'   => '',
            'sender_email'  => '',
            'site_name'     => '',
            'owner_name'    => '',
            'owner_email'   => '',
            'aff_name'      => '',
            'aff_url'       => '',
            'aff_email'     => '',
        ], array_map('strval', $context));

        return TemplateEngine::render($template, $escaped, [], $conditions);
    }
}
