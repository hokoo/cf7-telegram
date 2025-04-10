/* global cf7TelegramData */

import React from 'react';
import {createChannel} from "../utils/main";

const NewChannel = ({ setChannels }) => {
    const handleCreateChannel = async () => {
        try {
            await createChannel('Channel Name', setChannels);
        } catch (error) {
            console.error('Error creating channel:', error);
            alert('Failed to create channel');
        }
    };

    return (
        <button className="add-button add-channel-button" onClick={handleCreateChannel}>
            Create Channel
        </button>
    );
};

export default NewChannel;
