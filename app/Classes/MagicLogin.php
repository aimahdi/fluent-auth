<?php

namespace FluentSecurity\Classes;

use FluentSecurity\Helpers\Helper;

class MagicLogin
{
    private $assetLoaded = false;

    public function register()
    {
        add_action('login_form', array($this, 'maybePushMagicForm'));
        add_action('login_enqueue_scripts', array($this, 'pushAssets'));
        add_action('wp_ajax_nopriv_fls_magic_send_magic_email', array($this, 'handleMagicLoginAjax'));

        add_action('init', function () {
            if (isset($_GET['fls_al'])) {
                $hash = sanitize_text_field($_GET['fls_al']);
                $this->makeLogin($hash);
            }
        }, 1);
    }

    public function maybePushMagicForm()
    {
        if (!$this->isEnabled()) {
            return;
        }

        ?>
        <div style="display: none;" id="fls_magic_login">
            <div class="fls_magic_initial">
                <div class="fls_magic-or">
                    <span>Or</span>
                </div>
                <div class="fls_magic_login_btn">
                    <button class="fls_magic_show_btn button button-primary button-large">
                        <?php _e('Login Via Magic URL', 'fluent-security'); ?>
                    </button>
                </div>
            </div>
            <div style="display: none" class="fls_magic_login_form">
                <p class="fls_magic_text">
                    <?php _e('Enter the email address or username associated with your account, and we will send a direct login url to your inbox.', 'fluent-security'); ?>
                </p>
                <label>
                    <?php _e('Your Email/Username', 'fluent-security'); ?>
                    <input id="fls_magic_logon" class="fls_magic_input" type="text"/>
                    <input id="fls_magic_logon_nonce" type="hidden"
                           value="<?php echo wp_create_nonce('fls_magic_send_magic_email'); ?>"/>
                </label>
                <div class="fls_magic_submit_wrapper">
                    <button class="button button-primary button-large" id="fls_magic_submit">
                        <?php _e('Get Login URL', 'fluent-security'); ?>
                    </button>
                </div>

                <div class="magic_back_regular">
                    <div class="fls_magic-or">
                        <span><?php _e('Or', 'fluent-security'); ?></span>
                    </div>
                    <div class="fls_magic_login_back">
                        <button class="fls_magic_show_regular magic_btn_secondary">
                            <?php _e('Use Regular Login form', 'fluent-security'); ?>
                        </button>
                    </div>
                </div>
            </div>
        </div>
        <?php
    }

    public function pushAssets()
    {
        if ($this->assetLoaded || !$this->isEnabled()) {
            return;
        }

        wp_enqueue_script('fls_magic_url', FLUENT_SECURITY_PLUGIN_URL . 'dist/public/fls_login.js', array('jquery'), null, true);

        wp_localize_script('fls_magic_url', 'fls_magic_login_vars', [
            'ajaxurl'      => admin_url('admin-ajax.php'),
            'success_icon' => FLUENT_SECURITY_PLUGIN_URL . 'dist/images/success.png',
            'empty_text'   => __('Please provide username / email to get magic login link', 'ninja_magic_login'),
            'wait_text'    => __('Please Wait...', 'ninja_magic_login'),
        ]);

        $this->assetLoaded = true;
    }

    private function isEnabled()
    {
        return Helper::getSetting('extended_auth_security_type') == 'magic_login';
    }

    public function handleMagicLoginAjax()
    {
        if (!$this->isEnabled()) {
            wp_send_json_error([
                'message' => __('Login via URL is not activated', 'fluent-security')
            ], 423);
        }

        $loginLimit = Helper::getSetting('login_try_limit', 5);
        $timingMinutes = Helper::getSetting('login_try_timing', 30);

        $dateTime = date('Y-m-d H:i:s', current_time('timestamp') - $timingMinutes * 86400);

        $existingCount = flsDb()->table('fls_login_hashes')
            ->where('ip_address', Helper::getIp())
            ->where('created_at', '>', $dateTime)
            ->count();

        if ($existingCount > $loginLimit) {
            wp_send_json_error([
                'message' => sprintf(__('You are trying too much. Please try after %d minutes', 'fluent-security'), $timingMinutes)
            ], 423);
        }

        $username = sanitize_text_field($_REQUEST['email']);
        $nonce = sanitize_text_field($_REQUEST['_nonce']);

        // Verify the nonce now
        if (!wp_verify_nonce($nonce, 'fls_magic_send_magic_email')) {
            wp_send_json_error(array(
                'message' => __('Nonce Verification failed. Please try again', 'fluent-security')
            ), 423);
        }

        // Let's prepare
        if (strpos($username, '@')) {
            $user = get_user_by('email', $username);
        } else {
            $user = get_user_by('login', $username);
        }

        $canUseMagicLogin = apply_filters('fluent_security/magic_login_can_use', $this->canUseMagic($user), $user);

        if (!$canUseMagicLogin) {
            wp_send_json_error(array(
                'message' => __('Sorry, You can not login via magic url. Please use regular login form', 'fluent-security')
            ), 423);
        }

        // Now we have a valid user and let's send the email
        $validity = apply_filters('fluent_security/default_token_validity', 10, $user);

        $redirect_to = $this->getLoginRedirect($user);

        $loginUrl = esc_url($this->getMagicLoginUrl($user, $validity, false, $redirect_to));

        $emailSubject = sprintf(__('Sign in to %s', 'fluent-security'), get_bloginfo('name'));

        $emailLines = [
            sprintf(__('Hello %s,', 'fluent-security'), $user->display_name),
            sprintf(__('Click the link below to sign in to your %s account', 'fluent-security'), get_bloginfo('name')),
            sprintf(__('This link will expire in %d minutes and can only be used once.', 'fluent-security'), $validity)
        ];

        $callToAction = [
            'btn_text' => sprintf(__('Sign in to %s', 'ninja_magic_login'), get_bloginfo('name')),
            'url'      => $loginUrl
        ];

        $footerLines = [
            __('If the button above does not work, paste this link into your web browser:', 'fluent-security'),
            esc_url($loginUrl),
            ' ',
            'If you did not make this request, you can safely ignore this email.'
        ];

        $emailBody = '';
        $emailBody .= Helper::loadView('magic_login.header', [
            'pre_header' => $emailSubject
        ]);

        $emailBody .= Helper::loadView('magic_login.line_block', [
            'lines' => $emailLines
        ]);

        $emailBody .= Helper::loadView('magic_login.call_to_action', $callToAction);

        $emailBody .= Helper::loadView('magic_login.line_block', [
            'lines' => $footerLines
        ]);

        $emailBody .= Helper::loadView('magic_login.footer', []);

        $result = wp_mail($user->user_email, $emailSubject, $emailBody, array(
            'Content-Type: text/html; charset=UTF-8'
        ));

        $message = __('We just emailed a login link to your registered email. Click the link to sign in.', 'fluent-security');
        if (is_email($username)) {
            $message = sprintf(__('We just emailed a magic link to %s. Click the link to sign in.', 'fluent-security'), $user->user_email);
        }

        wp_send_json_success([
            'heading' => __('Check your inbox', 'fluent-security'),
            'result'  => $result,
            'message' => $message
        ], 200);
    }

    private function getMagicLoginUrl($user, $validity = 5, $baseUrl = false, $redirectIntend = '')
    {
        if (!$baseUrl) {
            $baseUrl = site_url('index.php');
        }

        if (!$redirectIntend && isset($_GET['redirect_to'])) {
            $redirectIntend = esc_url($_GET['redirect_to']);
        }

        $args = [
            'fls_al'         => $this->generateHash($user, $validity, $redirectIntend),
            'force_redirect' => 'yes'
        ];

        return add_query_arg($args, $baseUrl);
    }

    private function generateHash($user, $validity = 5, $redirectIntend = '') // $validity in minutes
    {
        if (!$user || !$this->isEnabled()) {
            return false;
        }

        // check if the user have any valid hashes
        $expectedValidity = current_time('mysql');

        $lastValid = flsDb()->table('fls_login_hashes')
            ->where('user_id', $user->ID)
            ->where('status', 'issued')
            ->where('valid_till', $expectedValidity)
            ->first();


        if ($lastValid) {
            flsDb()->table('fls_login_hashes')
                ->where('id', $lastValid->id)
                ->update([
                    'valid_till'      => date('Y-m-d H:i:s', current_time('timestamp') + $validity * 60),
                    'redirect_intend' => ($redirectIntend) ? $redirectIntend : $lastValid->redirect_intend,
                    'updated_at'      => current_time('mysql')
                ]);
            return $lastValid->login_hash;
        }

        $string = $user->ID . '-' . wp_generate_uuid4() . mt_rand(1, 99999999);
        $hash = wp_hash_password($string);
        $hash = sanitize_title($hash, '', 'display');
        $hash .= $user->ID . '-' . time();

        $data = array(
            'login_hash'      => $hash,
            'user_id'         => $user->ID,
            'status'          => 'issued',
            'ip_address'      => Helper::getIp(),
            'created_by'      => get_current_user_id(),
            'redirect_intend' => $redirectIntend,
            'valid_till'      => date('Y-m-d H:i:s', current_time('timestamp') + $validity * 60),
            'created_at'      => current_time('mysql'),
            'updated_at'      => current_time('mysql')
        );

        flsDb()->table('fls_login_hashes')
            ->insert($data);

        return $hash;
    }

    public function makeLogin($hash)
    {
        if (!$this->isEnabled()) {
            return false;
        }

        // Check if user logged in
        if (is_user_logged_in()) {
            $userId = get_current_user_id();
            flsDb()->table('fls_login_hashes')
                ->where('user_id', $userId)
                ->where('login_hash', $hash)
                ->update([
                    'status'             => 'already_logged_in',
                    'success_ip_address' => Helper::getIp(),
                    'updated_at'         => current_time('mysql')
                ]);
            return true;
        }

        $row = flsDb()->table('fls_login_hashes')
            ->where('login_hash', $hash)
            ->where('status', 'issued')
            ->first();

        if (!$row) {
            return false;
        }

        $currentTimeStamp = current_time('timestamp');
        $validityTimestamp = strtotime($row->valid_till, $currentTimeStamp);

        if ($validityTimestamp < $currentTimeStamp) {
            // this is an invalid timestamp
            flsDb()->table('fls_login_hashes')
                ->where('id', $row->id)
                ->update([
                    'status'    => 'expired',
                    'update_at' => current_time('mysql')
                ]);
            return false;
        }

        // The hash is valid now make the user logged in
        $userId = $row->user_id;
        $user = get_user_by('ID', $userId);

        if (!apply_filters('fluent_security/magic_login_can_use', $this->canUseMagic($user), $user)) {
            return false;
        }

        add_filter('authenticate', array($this, 'allowProgrammaticLogin'), 10, 3);    // hook in earlier than other callbacks to short-circuit them
        $user = wp_signon(array('user_login' => $user->user_login));
        remove_filter('authenticate', array($this, 'allowProgrammaticLogin'), 10, 3);

        if ($user instanceof \WP_User) {
            wp_set_current_user($user->ID, $user->user_login);
            if (is_user_logged_in()) {
                flsDb()->table('fls_login_hashes')
                    ->where('id', $row->id)
                    ->update([
                        'status'             => 'used',
                        'success_ip_address' => Helper::getIp(),
                        'updated_at'         => current_time('mysql')
                    ]);

                if (isset($_GET['force_redirect']) && $_GET['force_redirect'] == 'yes') {
                    if ($row->redirect_intend) {
                        wp_safe_redirect($row->redirect_intend);
                    } else {
                        wp_safe_redirect($this->getLoginRedirect($user));
                    }
                    exit();
                }

                return true;
            }
        }

        return false;
    }

    public function allowProgrammaticLogin($user, $username, $password)
    {
        return get_user_by('login', $username);
    }

    private function getLoginRedirect($user)
    {
        $requested_redirect_to = isset($_REQUEST['redirect_to']) ? $_REQUEST['redirect_to'] : site_url();
        return apply_filters('login_redirect', $requested_redirect_to, $requested_redirect_to, $user);
    }

    /**
     * @param $user \WP_User
     * @return bool|mixed
     */
    private function canUseMagic($user)
    {
        if (!$user) {
            return false;
        }

        $roles = Helper::getSetting('magic_user_roles');

        if (!$roles) {
            return true;
        }

        return !!array_intersect($roles, (array)$user->roles);
    }

}
