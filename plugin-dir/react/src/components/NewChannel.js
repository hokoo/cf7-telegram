/* global cf7TelegramData, wp */

import React from 'react';
import {createChannel} from "../utils/main";

const NewChannel = ({setChannels, disabled = false}) => {
    const handleCreateChannel = async () => {
        try {
            await createChannel(wp.i18n.__( 'Bridge Name', 'cf7-telegram' ), setChannels);
        } catch (error) {
            console.error('Error creating bridge:', error);
            alert( wp.i18n.__( 'Failed to create bridge', 'cf7-telegram' ) );
        }
    };

    return (
        <button
            className="add-button add-channel-button"
            data-testid="cf7tg-create-channel"
            onClick={handleCreateChannel}
            disabled={disabled}
        >
            {wp.i18n.__( 'Create Bridge', 'cf7-telegram' )}
        </button>
    );
};

export default NewChannel;
