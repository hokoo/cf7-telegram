import React from 'react';
import { getChatStatus, getToggleButtonLabel } from '../utils/chatStatus';

const BotView = ({
                     bot,
                     chatsForBot = [],
                     botsChatRelations = [],
                     updatingStatusIds = [],
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
                     setTokenValue,
                     handleToggleChatStatus,
                     online
                 }) => {
    return (
        <div className="entity-wrapper bot-wrapper">
            <div className="frame bot-summary">
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

                <div className={`bot-status ${online === true ? 'online' : online === false ? 'offline' : 'unknown'}`}>
                    Status: {online === true ? 'Online' : online === false ? 'Offline' : 'Unknown'}
                </div>
            </div>

            {error && <p style={{ color: 'red' }}>{error}</p>}
            {saving && <p>Saving...</p>}

            <div className="frame chats-for-bot">
                <h5>Chats</h5>
                {chatsForBot.length > 0 ? (
                    <ul>
                        {chatsForBot.map(chat => {
                            const status = getChatStatus(bot.id, chat.id, botsChatRelations);
                            const isUpdating = updatingStatusIds.includes(chat.id);
                            return (
                                <li key={chat.id}>
                                    {chat.title.rendered}
                                    <span className={`chat-status ${status.toLowerCase()}`}> ({status})</span>
                                    <button
                                        onClick={() => handleToggleChatStatus(chat.id, status.toLowerCase())}
                                        style={{ marginLeft: '0.5em' }}
                                        disabled={isUpdating}
                                    >{isUpdating ? '⏳ Updating...' : getToggleButtonLabel(status)}</button>
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
