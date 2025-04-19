/* global cf7TelegramData, wp */

import React from 'react';
import {createChannel} from "../utils/main";

const NewChannel = ({setChannels}) => {
    const handleCreateChannel = async () => {
        try {
            await createChannel('Channel Name', setChannels);
        } catch (error) {
            console.error('Error creating channel:', error);
            alert( wp.i18n.__( 'Failed to create channel', 'cf7-telegram' ) );
        }
    };

    return (
        <button className="add-button add-channel-button" onClick={handleCreateChannel}>
            {wp.i18n.__( 'Create Channel', 'cf7-telegram' )}
        </button>
    );
};

export default NewChannel;
