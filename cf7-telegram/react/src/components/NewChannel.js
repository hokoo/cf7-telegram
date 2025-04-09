/* global cf7TelegramData */

import React from 'react';

const NewChannel = ({ setChannels }) => {
    const handleCreateChannel = async () => {
        const newChannelData = {
            title: 'Channel Name',
        };

        try {
            const response = await fetch(cf7TelegramData.routes.channels, {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'X-WP-Nonce': cf7TelegramData?.nonce,
                },
                body: JSON.stringify(newChannelData)
            });

            if (!response.ok) throw new Error('Failed to create channel');

            const createdChannel = await response.json();
            setChannels(prev => [...prev, createdChannel]);
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
