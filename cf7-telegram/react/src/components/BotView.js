import React from 'react';
import { getChatStatus, getToggleButtonLabel } from '../utils/chatStatus';

const BotView = ({
                     bot,
                     chatsForBot = [],
                     botsChatRelations = [],
                     updatingStatusIds = [],
                     isEditingToken,
                     nameValue,
                     tokenValue,
                     trimmedToken,
                     saving,
                     error,
                     handleEditToken,
                     cancelEdit,
                     saveBot,
                     deleteBot,
                     handleKeyDown,
                     setTokenValue,
                     handleToggleChatStatus,
                     online
                 }) => {
    return (
        <div className={`entity-wrapper bot-wrapper ${saving ? 'saving' : ''} ${online === true ? 'online' : online === false ? 'offline' : 'unknown'}`}>
        <div className="frame bot-summary">
                <div className="bot-title">
                    <div className="bot-name">
                        {nameValue}
                    </div>
                </div>

                <div className="bot-token">
                    <span onClick={handleEditToken} style={{ cursor: 'pointer' }}>
                        token: <span className="token-value">{trimmedToken}</span>
                    </span>

                    {isEditingToken && (
                        <input
                            className="edit-token"
                            type="text"
                            value={tokenValue}
                            onChange={e => setTokenValue(e.target.value)}
                            onKeyDown={handleKeyDown}
                            autoFocus
                            disabled={saving}
                        />
                    )}
                </div>
            </div>

            {error && <p style={{ color: 'red' }}>{error}</p>}

            <div className="frame chats-for-bot">
                <h5>Chats</h5>
                {chatsForBot.length > 0 ? (
                    <ul>
                        {chatsForBot.map(chat => {
                            const status = getChatStatus(bot.id, chat.id, botsChatRelations);
                            const isUpdating = updatingStatusIds.includes(chat.id);
                            return (
                                <li key={chat.id} className={`chat-item ${status.toLowerCase()}`}>
                                    {chat.title.rendered}

                                    <span
                                        onClick={() => handleToggleChatStatus(chat.id, status.toLowerCase())}
                                        style={{ marginLeft: '0.5em' }}
                                        disabled={isUpdating}
                                    >{isUpdating ? 'Updating...' : getToggleButtonLabel(status)}</span>
                                </li>
                            );
                        })}
                    </ul>
                ) : (
                    <p>No chats assigned to this bot</p>
                )}
            </div>

            <div className="bot-actions">
                <button onClick={deleteBot} disabled={saving} style={{ marginTop: '1em', color: 'red' }}>
                    🗑️ Delete bot
                </button>
            </div>
        </div>
    );
};

export default BotView;
