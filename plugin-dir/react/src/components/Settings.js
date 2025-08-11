/* global cf7TelegramData, wp */

import React, { useEffect, useState } from 'react';
import { apiFetchSettings, apiSaveSettings } from "../utils/api";
import { sprintf } from "../utils/main";

const Settings = () => {
    const [ea, setEarlyAccess] = useState(false);
    const [saving, setSaving] = useState(false);
    const [loaded, setLoaded] = useState(false);

    useEffect(() => {
        const fetchSettings = async () => {
            const settings = await apiFetchSettings();
            setEarlyAccess(settings[cf7TelegramData.options.early_access]);
            setLoaded(true);
        };

        fetchSettings();
    }, []);

    const handleChange = async (event) => {
        const newValue = event.target.checked;
        setEarlyAccess(newValue);
        setSaving(true);

        try {
            await apiSaveSettings({ [cf7TelegramData.options.early_access]: newValue });
        } finally {
            setSaving(false);
        }
    };

    if (!loaded) {
        return <div>{wp.i18n.__('Loading setting...', 'cf7-telegram')}</div>;
    }

    return (
        <label
            className={`early-access ${ea ? 'checked' : ''}`}
            disabled={saving}
        >
            <input
                type="checkbox"
                checked={ea}
                onChange={handleChange}
                disabled={saving}
            />
            {wp.i18n.__( 'Install pre-releases (unstable, but exciting!)', 'cf7-telegram' )}
            <small
                dangerouslySetInnerHTML={{
                    __html: sprintf(
                        wp.i18n.__(
                            'You might run into terrible bugs. If that doesn’t scare you off, I’d love to hear your %sfeedback on GitHub%s!',
                            'cf7-telegram'
                        ),
                        '<a target="_blank" href="https://github.com/hokoo/cf7-telegram/issues">',
                        '</a>'
                    ),
                }}
            />

            {ea && (
                <small
                    dangerouslySetInnerHTML={{
                        __html:
                            wp.i18n.__( 'You might need a personal GitHub token to access pre-releases — especially if your server has hit GitHub’s free rate limit. <br>You\'ll see an error when checking for updates if that\'s the case (this can happen even if you didn’t cause it). <br>Getting a token is free and takes just a minute to create.', 'cf7-telegram' ),
                    }}
                />
            )}

            {ea && (
                <small
                    dangerouslySetInnerHTML={{
                        __html:
                            sprintf(
                                wp.i18n.__( 'Got the token? Just drop it into the %s constant in wp-config.php.', 'cf7-telegram' ),
                                '<code>WPCF7TG_GITHUB_TOKEN</code>'
                            )
                    }}
                />
            )}

            {ea && (
                <small
                    dangerouslySetInnerHTML={{
                        __html:
                            wp.i18n.__( 'For better experience, install and activate the following plugins:', 'cf7-telegram' ) +
                            '<br><a target="_blank" href="https://wordpress.org/plugins/wp-data-logger/">WP Data Logger</a>.<br/>' +
                            wp.i18n.__( 'Caught a bug? Take a look at the logs under Tools → WP Logger.', 'cf7-telegram' ),
                    }}
                />
            )}
        </label>
    );
};

export default Settings;
