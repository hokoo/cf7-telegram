/* global cf7TelegramData */

import React from 'react';

const BotView = ({
                     bot,
                     chatsForBot = [],
                     isEditingName,
                     isEditingToken,
                     nameValue,
                     tokenValue,
                     saving,
                     error,
                     handleEditName,
                     handleEditToken,
                     cancelEdit,
                     saveBot,
                     deleteBot,
                     handleKeyDown,
                     setNameValue,
                     setTokenValue
                 }) => {
    return (
        <div className="cf7tg-bot-wrapper">
            <div className="bot-title">
                {isEditingName ? (
                    <div className="edit-title">
                        <input
                            type="text"
                            value={nameValue}
                            onChange={e => setNameValue(e.target.value)}
                            onKeyDown={handleKeyDown}
                            autoFocus
                            disabled={saving}
                        />
                        <button onClick={saveBot} disabled={saving}>💾</button>
                        <button onClick={cancelEdit} disabled={saving}>❌</button>
                    </div>
                ) : (
                    <h4 onClick={handleEditName} style={{ cursor: 'pointer' }}>
                        {nameValue} <span style={{ marginLeft: 6 }}>✏️</span>
                    </h4>
                )}
            </div>

            <div className="bot-token">
                {isEditingToken ? (
                    <div className="edit-token">
                        <input
                            type="text"
                            value={tokenValue}
                            onChange={e => setTokenValue(e.target.value)}
                            onKeyDown={handleKeyDown}
                            autoFocus
                            disabled={saving}
                        />
                        <button onClick={saveBot} disabled={saving}>💾</button>
                        <button onClick={cancelEdit} disabled={saving}>❌</button>
                    </div>
                ) : (
                    <span onClick={handleEditToken} style={{ cursor: 'pointer' }}>
            token: <span className="token-value">{tokenValue}</span> <span style={{ marginLeft: 6 }}>✏️</span>
          </span>
                )}
            </div>

            {error && <p style={{ color: 'red' }}>{error}</p>}
            {saving && <p>Saving...</p>}

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

            <div className="bot-actions">
                <button onClick={deleteBot} disabled={saving} style={{ marginTop: '1em', color: 'red' }}>
                    🗑️ Delete bot
                </button>
            </div>
        </div>
    );
};

export default BotView;
