<i>or login with the following:</i>
<ul>
    <?php
    $options = get_option("OpenIDConnectLoginPlugin_options");

    foreach ($options['openid_provider_hash'] as $provider => $value) {
        ?>
        <li>
            <a href="<?php echo get_site_url(); ?>/?openid-connect=<?php echo urlencode($provider); ?>"><?php echo $provider; ?></a>
        </li>
    <?php } ?>
</ul>