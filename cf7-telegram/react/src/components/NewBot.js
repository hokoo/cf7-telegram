/* global cf7TelegramData */

import React from 'react';
import {apiCreateBot} from "../utils/api";

const NewBot = ({setBots}) => {
    const handleCreateBot = async () => {
        try {
            let bot = await apiCreateBot('Bot Name', '[empty]')
            setBots(prev => [...prev, bot])
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
