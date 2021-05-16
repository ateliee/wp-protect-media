<?php
class ProtectMedia
{
    const PLUGIN_ID         = 'protect-media';
    const PLUGIN_SETTING_SLAG = 'protect-media-config';
    const PLUGIN_SETTING_TRANSIENT_MESSAGE = self::PLUGIN_SETTING_SLAG.'-message';
    const PLUGIN_SETTING_TRANSIENT_ERROR = self::PLUGIN_SETTING_SLAG.'-error';
    const CREDENTIAL_ACTION   = self::PLUGIN_ID . '-nonce-action';
    const CREDENTIAL_NAME     = self::PLUGIN_ID . '-nonce-key';

    const PLUGIN_DB_PREFIX  = self::PLUGIN_ID . '_';
    const PLUGIN_DB_SETTING_PATH  = self::PLUGIN_DB_PREFIX . 'path';
    const PLUGIN_DB_SETTING_BLOCK  = self::PLUGIN_DB_PREFIX . 'block';

    const HTACCESS_PATH = WP_CONTENT_DIR.'/.htaccess';
    const REWRITE_ENDPOINT = __DIR__.'/get.php';

    static function init()
    {
        return new self();
    }

    function __construct()
    {
        if (is_admin() && is_user_logged_in()) {
            add_action('admin_menu', [$this, 'set_plugin_menu']);
            add_action('admin_init', [$this, 'save_config']);
        }
    }

    function set_plugin_menu(){
        add_submenu_page(
            'upload.php',
            'Protect Media Setting',
            'Protect Media',
            'manage_options',
            self::PLUGIN_SETTING_SLAG,
            [$this, 'show_config_form']
        );
    }

    /**
     * @return bool
     */
    protected function is_allow_override(){
        return ini_get('allow_override');
    }

    /**
     * 設定画面
     */
    public function show_config_form() {
        $path = get_option(self::PLUGIN_DB_SETTING_PATH);
        $is_block = get_option(self::PLUGIN_DB_SETTING_BLOCK);
        $is_error = get_transient(self::PLUGIN_SETTING_TRANSIENT_ERROR);
        ?>
        <div class="wrap">
            <h1>Protect Media Settings</h1>
            <p>
                設定されたパスのファイルアクセスをログイン必須にします。
            </p>
<?php if(!$this->is_allow_override()){ ?>
    <div class="notice"><p><?php $this->is_allow_override(); ?>php.iniのAllowOverrideが有効か確認してください。</p></div>
<?php } ?>
<?php if(file_exists(self::HTACCESS_PATH) && !is_writable(self::HTACCESS_PATH)){ ?>
    <?php if($is_error){ ?>
        <div class="notice notice-alt notice-error"><p>.htaccessへの書き込み権限がありません。下記を<?php self::HTACCESS_PATH; ?>に記載ください</p></div>
        <div class="notice notice-error">
        <pre><?php esc_html($this->get_htaccess_str($path)); ?></pre>
        </div>
    <?php }else{ ?>
        <div class="notice notice-alt notice-error"><p>.htaccessへの書き込み権限がありません。</p></div>
    <?php } ?>
<?php } ?>
<?php if(!$path){ ?>
    <div class="notice notice-alt notice-error"><p>パスが無効のため保護が有効化されていません。</p></div>
<?php } ?>
<?php if($message = get_transient(self::PLUGIN_SETTING_TRANSIENT_MESSAGE)){ ?>
    <div id="message" class="notice notice-success"><p><?php esc_html($message); ?></p></div>
<?php } ?>
            <form action="" method='post' id="my-submenu-form">
                <?php wp_nonce_field(self::CREDENTIAL_ACTION, self::CREDENTIAL_NAME) ?>
                <p>
                    <label for="title">パス設定</label>
                    <input type="text" name="path" value="<?php esc_html($path); ?>" placeholder="uploadsからのパスを指定してください" size="60" />
                </p>
                <p>
                    <label for="title">アクセスを全て拒否</label>
                    <input type="checkbox" name="block" value="1" <?php if($is_block){ ?> checked="checked"<?php } ?> />
                </p>

                <p><input type="submit" value="Save" class="button button-primary button-large"></p>
            </form>
        </div>
        <?php
    }

    public function save_config()
    {
        if (isset($_POST[self::CREDENTIAL_NAME]) && $_POST[self::CREDENTIAL_NAME]) {
            if (check_admin_referer(self::CREDENTIAL_ACTION, self::CREDENTIAL_NAME)) {

                $path = isset($_POST['path']) ? $_POST['path'] : "";
                $is_block = isset($_POST['block']) ? $_POST['block'] : 0;

                if($this->update_path($path)){
                    update_option(self::PLUGIN_DB_SETTING_BLOCK, $is_block);
                    $completed_text = "設定の保存が完了しました。";
                    set_transient(self::PLUGIN_SETTING_TRANSIENT_MESSAGE, $completed_text, 5);
                }else{
                    set_transient(self::PLUGIN_SETTING_TRANSIENT_ERROR, 1, 5);
                }
                // 設定画面にリダイレクト
                wp_safe_redirect(menu_page_url(self::PLUGIN_SETTING_SLAG, false));
                exit;
            }
        }
    }

    /**
     * 保護先のパス
     *
     * @param string|null $path
     * @return string|false
     */
    public static function get_protect_dir($path = null){
        if($path === null){
            $path = get_option(self::PLUGIN_DB_SETTING_PATH);
        }
        if(!$path){
            return false;
        }
        return wp_upload_dir()['basedir'].DIRECTORY_SEPARATOR.$path;
    }

    /**
     * @return bool
     */
    public static function is_block(){
        return get_option(self::PLUGIN_DB_SETTING_BLOCK) ? true : false;
    }

    /**
     * @param string $path
     * @return string
     */
    protected function get_htaccess_str($path){
        $endpoint = self::REWRITE_ENDPOINT;
        $wp_path = rtrim(ABSPATH, DIRECTORY_SEPARATOR);
        if(strpos($endpoint, $wp_path) === 0){
            $endpoint = substr($endpoint, strlen($wp_path));
        }
        $uploads_dir = self::get_protect_dir($path);
        if(strpos($uploads_dir, $wp_path) === 0){
            $uploads_dir = substr($uploads_dir, strlen($wp_path));
        }
        return <<< EOF
RewriteEngine On
RewriteBase /
RewriteCond %{REQUEST_URI} ^$uploads_dir
RewriteRule ^(.*)$ $endpoint [QSA,L]
EOF;
    }
    /**
     * パスの保存
     *
     * @param string $path
     * @return bool
     */
    protected function update_path($path){
        update_option(self::PLUGIN_DB_SETTING_PATH, $path);

        $str = '';
        if(file_exists(self::HTACCESS_PATH)){
            $str = @file_get_contents(self::HTACCESS_PATH);
            if($str === false){
                $completed_text = ".htaccessが読み込めませんでした。";
                set_transient(self::PLUGIN_SETTING_SLAG, $completed_text, 5);
                return false;
            }
        }
        $plugin_id = self::PLUGIN_ID;
        $body_str = $this->get_htaccess_str($path);
        $begin_tag = '# BEGIN '.$plugin_id;
        $end_tag = '# END '.$plugin_id;
        $hint_tag = '# これらのマーカー間にあるディレクティブへのいかなる変更も上書きされてしまいます。';
        if(preg_match('/^(.*'.preg_quote($begin_tag).'\n)(.*\n)('.preg_quote($end_tag).'.*)$/s', $str, $matchs)){
            $str = $matchs[1].$hint_tag."\n".$body_str."\n".$matchs[3];
        }else{
            $str .= $begin_tag."\n".$hint_tag."\n".$body_str."\n".$end_tag;
        }
        if(!$str){
        }
        if(@file_put_contents(self::HTACCESS_PATH, $str) === false){
            $completed_text = ".htaccessへの書き込みに失敗しました。";
            set_transient(self::PLUGIN_SETTING_SLAG, $completed_text, 5);
            return false;
        }
        return true;
    }
}