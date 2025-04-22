/* global cf7TelegramData, wp */

import React from 'react';
import {apiCreateBot} from "../utils/api";

const NewBot = ({setBots}) => {
    const handleCreateBot = async () => {
        try {
            let bot = await apiCreateBot(wp.i18n.__( 'Bot Name', 'cf7-telegram' ), cf7TelegramData.phrases.empty)
            setBots(prev => [...prev, bot])
        } catch (error) {
            console.error('Error creating bot:', error);
            alert( wp.i18n.__( 'Failed to create bot', 'cf7-telegram' ) );
        }
    };

    return (
        <button className="add-button add-bot-button" onClick={handleCreateBot}>
            {wp.i18n.__( 'Create Bot', 'cf7-telegram' )}
        </button>
    );
};

export default NewBot;
