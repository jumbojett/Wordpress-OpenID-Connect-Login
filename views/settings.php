<?php
if (!current_user_can('manage_options'))
    wp_die(__('You do not have sufficient permissions to manage options for this site.'));
?>
<div class="wrap">
    <h2>OpenID Connect</h2>

    <p><strong>Important!</strong> register the following redirect uri with your provider:
        (<i><?php echo get_site_url(); ?>/?openid-connect=endpoint</i>)</p>

    <form action="options.php" method="post">


        <?php settings_fields($this->get_option_name()); ?>
        <?php do_settings_sections('openid-connect'); ?>

        <p class="submit"><input type="submit" value="<?php esc_attr_e('Save Changes'); ?>"
                                 class="button button-primary" id="submit" name="submit"></p>

    </form>
</div>