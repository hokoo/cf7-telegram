/* global cf7TelegramData */

import React from 'react';

const NewBot = ({ setBots }) => {
    const handleCreateBot = async () => {
        const newBotData = {
            title: 'Bot Name',
            token: '[empty]',
            status: 'publish',
        };

        try {
            const response = await fetch(cf7TelegramData.routes.bots, {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'X-WP-Nonce': cf7TelegramData?.nonce,
                },
                body: JSON.stringify(newBotData)
            });

            if (!response.ok) throw new Error('Failed to create bot');

            const createdBot = await response.json();
            setBots(prev => [...prev, createdBot]);
        } catch (error) {
            console.error('Error creating bot:', error);
            alert('Failed to create bot');
        }
    };

    return (
        <button className="add-button add-bot-button" onClick={handleCreateBot}>
            Create Bot
        </button>
    );
};

export default NewBot;
