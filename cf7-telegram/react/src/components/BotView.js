import React from 'react';

const BotView = ({ bot, chatsForBot = [] }) => {
    return (
        <div className="cf7tg-bot-wrapper">
            <h4>{bot.title.rendered}</h4>
            <span className="bot-token">token: {bot.token}</span>

            {chatsForBot.length > 0 ? (
                <div className="chats-for-bot">
                    <h5>Chats</h5>
                    <ul>
                        {chatsForBot.map(chat => (
                            <li key={chat.id}>{chat.title.rendered}</li>
                        ))}
                    </ul>
                </div>
            ) : (
                <p>No chats assigned to this bot</p>
            )}
        </div>
    );
};

export default BotView;
