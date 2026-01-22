/* global wp */

import React, {useEffect, useRef} from 'react';
import {copyWithTooltip} from '../utils/main';
import {getChatStatus, getToggleButtonLabel} from '../utils/chatStatus';

const BotView = ({
    bot,
    chatsForBot = [],
    bot2ChatConnections = [],
    updatingStatusIds = [],
    isEditingToken,
    nameValue,
    isTokenEmpty,
    tokenValue,
    saving,
    error,
    handleEditToken,
    deleteBot,
    handleKeyDown,
    setTokenValue,
    handleToggleChatStatus,
    handleDisconnectChat,
    online,
    renderEditTokenCount
}) => {

    let status = online === true ? 'online' : online === false ? 'offline' : 'unknown';
    let truncatedName = nameValue.slice(0, 18);

    isEditingToken && renderEditTokenCount.current < 1 && setTokenValue('');
    isEditingToken && renderEditTokenCount.current++;

    // Trimmed token for display (only last 4 characters)
    const trimmedToken = isTokenEmpty ? tokenValue : `***${tokenValue.slice(-4)}`;

    return (
        <div className={`entity-container bot ${status}`} key={bot.id} id={`bot-${bot.id}`}>
            <div className={`entity-wrapper bot-wrapper ${saving ? 'saving' : ''}`}>
                <div className="frame bot-summary">
                    <div className="bot-title">
                        <div
                            className={`bot-name ${status} copyable`}
                            onClick={(e) => copyWithTooltip(e.target)}
                            title={wp.i18n.__( 'Click to copy bot name', 'cf7-telegram' )}
                        >
                            @{truncatedName}{truncatedName !== nameValue && '...'}
                        </div>

                        <div
                            className={`bot-command copyable`}
                            onClick={(e) => copyWithTooltip(e.target)}
                            title={wp.i18n.__( 'Click to copy bot command', 'cf7-telegram' )}
                            >
                            /cf7tg_start
                        </div>
                    </div>

                    <div className="bot-token">
                        <div
                            className={`show-token` + (bot.isTokenDefinedByConst ? ' const' : '')}
                            onClick={handleEditToken}
                            title={(bot.isTokenDefinedByConst ?
                                wp.i18n.__( 'Defined by PHP constant', 'cf7-telegram' ) :
                                wp.i18n.__( 'Click to edit token', 'cf7-telegram' ))}
                        >
                            {wp.i18n.__( 'token', 'cf7-telegram' )}: <span className="token-value">{isEditingToken ? '' : trimmedToken}</span>
                        </div>

                        {!online && isTokenEmpty && ! bot.isTokenDefinedByConst && (
                            <div
                                className="php-const-hint copyable"
                                title={wp.i18n.__( 'Click to copy PHP code', 'cf7-telegram' )}
                                onClick={(e) => copyWithTooltip(e.target, `const ${bot.phpConst} = 'your_token';`)}
                            >
                                {wp.i18n.__( 'set by PHP const', 'cf7-telegram' )}
                            </div>
                        )}

                        {isEditingToken && (
                            <>
                            <input
                                className="edit-token"
                                type="text"
                                value={tokenValue}
                                onChange={e => setTokenValue(e.target.value)}
                                onKeyDown={handleKeyDown}
                                autoFocus
                                disabled={saving}
                                spellCheck="false"
                                title={wp.i18n.__( 'Press Enter to save token, Esc to cancel.', 'cf7-telegram' )}
                            />

                            </>
                        )}
                    </div>
                </div>

                {error && <p style={{color: 'red'}}>{error}</p>}

                <div className="frame chats-for-bot">
                    {chatsForBot.length > 0 ? (
                        <ul>
                            {chatsForBot.map(chat => {
                                const status = getChatStatus(bot.id, chat.id, bot2ChatConnections);
                                const isUpdating = updatingStatusIds.includes(chat.id);
                                return (
                                    <li key={chat.id} className={`chat-item ${status.toLowerCase()}`}>
                                        <span className="chat-name"
                                              title={status.toWellFormed()}
                                        >{chat.title.rendered}</span>

                                        <span
                                            className="action toggle-status"
                                            onClick={() => handleToggleChatStatus(chat.id, status.toLowerCase())}
                                            disabled={isUpdating}
                                        >{isUpdating ? wp.i18n.__( 'Updating...', 'cf7-telegram' ) : getToggleButtonLabel(status)}</span>

                                        <span
                                            className="action remove-chat"
                                            onClick={() => handleDisconnectChat(chat.id, bot.id)}
                                        >{wp.i18n.__( 'Remove', 'cf7-telegram' )}</span>
                                    </li>
                                );
                            })}
                        </ul>
                    ) : 'offline' === status ? (
                        <span className="offline-bot-sad-message">{ wp.i18n.__( 'Couldn\'t load chat list...', 'cf7-telegram' ) }</span>
                    ) : 'unknown' === status ? (
                        <span className="unknown-bot-status-message">{ wp.i18n.__( 'Trying to load chat list...', 'cf7-telegram' ) }</span>
                    ) : (
                        <span className="no-chats-found">{ wp.i18n.__( 'Waiting for chats to join...', 'cf7-telegram' ) }</span>
                    )}
                </div>

                <div className="frame status-bar">
                    <button
                        className="remove-bot-button"
                        onClick={deleteBot}
                        disabled={saving}>
                        {wp.i18n.__( 'Remove bot', 'cf7-telegram' )}
                    </button>
                    <div className={`bot-status ${status}`}>{status}</div>
                </div>

            </div>
        </div>
    );
};

export default BotView;
