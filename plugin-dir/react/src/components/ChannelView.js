/* global cf7TelegramData, wp */

import React from 'react';
import Select from 'react-select';

const ChannelView = ({
    channel,
    titleValue,
    saving,
    error,
    handleTitleClick,
    handleTitleChange,
    handleKeyDown,
    handleCancelEdit,
    saveTitle,
    botForChannel,
    chatsForChannel = [],
    formsForChannel = [],
    availableForms = [],
    showFormSelector,
    handleAddForm,
    handleFormSelect,
    handleRemoveForm,
    availableBots = [],
    handleBotSelect,
    handleRemoveBot,
    bot2ChatConnections = [],
    handleToggleChat,
    deleteChannel,
    getToggleButtonLabel,
    dataAvailability = {forms: 'ready', bots: 'ready', chats: 'ready'},
    mutatingRelations = false
}) => {
    const renderedChats = (botForChannel?.chats || [])
        .map(chat => {
            const relation = bot2ChatConnections.find(rel => rel.data.from === botForChannel.id && rel.data.to === chat.id);
            const statusMeta = relation?.data?.meta?.status?.[0] || null;

            if (statusMeta === 'pending') return null;

            const isLinkedToChannel = chatsForChannel.some(c => c.id === chat.id);

            let status = isLinkedToChannel ? 'Active' : 'Paused';
            if (statusMeta === 'muted') {
                status = 'Muted';
            }

            return {
                ...chat,
                status: status
            };
        })
        .filter(Boolean);

    const renderChannelClasses = () => {
        let classes = '';

        // Has bot online.
        if (botForChannel?.online) {
            classes += ' has-bot-online';
        }

        // Has at least one active chat.
        if (renderedChats.some(chat => chat.status === 'Active')) {
            classes += ' has-active-chats';
        }

        // Has at least one form assigned.
        if (formsForChannel.length > 0) {
            classes += ' has-forms';
        }

        return classes;
    }

    return (
        <div className={`entity-container channel` + renderChannelClasses()} key={channel.id} id={`channel-${channel.id}`}>
            <div className="entity-wrapper channel-wrapper">
                <div className={`frame channel-title-wrapper`}>
                    <div className="columns">
                        <div className="column title-column">
                            <input
                                className="edit-title"
                                type="text"
                                value={titleValue}
                                onChange={handleTitleChange}
                                onKeyDown={handleKeyDown}
                                onBlur={saveTitle}
                                disabled={saving}
                                autoFocus
                            />
                        </div>

                        <div className="column bot-column">
                            {'error' === dataAvailability.bots ? (
                                <span className="resource-error">{wp.i18n.__( 'Bots are unavailable.', 'cf7-telegram' )}</span>
                            ) : 'ready' !== dataAvailability.bots ? (
                                <span className="resource-loading">{wp.i18n.__( 'Loading bots...', 'cf7-telegram' )}</span>
                            ) : botForChannel ? (
                                <div data-Bot-Id={botForChannel.id} className={`bot-for-channel ` + (botForChannel?.online ? 'online' : 'offline')}>
                                    <span>{botForChannel.title.rendered}</span>
                                    <button
                                        className="detach-button detach-bot-button crux"
                                        onClick={handleRemoveBot}
                                        disabled={mutatingRelations}
                                    ></button>
                                </div>
                            ) : (
                                <>
                                    {availableBots.length > 0 && (
                                        <Select
                                            className="select-picker bot-picker"
                                            classNamePrefix="select-picker"
                                            options={availableBots.map(bot => ({
                                                value: bot.id,
                                                label: bot.title.rendered
                                            }))}
                                            isSearchable={false}
                                            placeholder={wp.i18n.__( 'Pick a bot', 'cf7-telegram' )}
                                            onChange={(selectedOption) => handleBotSelect({target: {value: selectedOption?.value}})}
                                            isClearable
                                            isDisabled={mutatingRelations}
                                        />
                                    )}
                                </>
                            )}
                        </div>
                    </div>
                </div>

                <div className="frame chats">
                    {'error' === dataAvailability.chats ? (
                        <span className="resource-error">{wp.i18n.__( 'Chat data is unavailable.', 'cf7-telegram' )}</span>
                    ) : 'ready' !== dataAvailability.chats ? (
                        <span className="resource-loading">{wp.i18n.__( 'Loading chat data...', 'cf7-telegram' )}</span>
                    ) : renderedChats.length > 0 ? (
                        <>
                            {renderedChats.map(chat => (
                                <div
                                    key={chat.id}
                                    className={`chat chat-${chat.id} ${chat.status.toLowerCase()}`}
                                    onClick={() => !mutatingRelations && handleToggleChat(chat.id, chat.status)}
                                    aria-disabled={mutatingRelations}
                                    title={getToggleButtonLabel(chat.status)}
                                >
                                    <span className={`chat-username`}>{chat.title.rendered}</span>
                                </div>
                            ))}
                        </>
                    ) : (
                        <span className="no-chats-found">[{wp.i18n.__( 'No chats assigned to this channel', 'cf7-telegram' )}]</span>
                    )}
                </div>

                <div className="frame forms">
                    <button
                        className="add-button add-form-button"
                        onClick={handleAddForm}
                        disabled={'ready' !== dataAvailability.forms || mutatingRelations}
                    >
                        {!showFormSelector ?
                            (wp.i18n.__( 'Add Form', 'cf7-telegram' )) :
                            (wp.i18n.__( 'Cancel', 'cf7-telegram' ))}
                    </button>
                    {'error' === dataAvailability.forms ? (
                        <span className="resource-error">{wp.i18n.__( 'Forms are unavailable.', 'cf7-telegram' )}</span>
                    ) : 'ready' !== dataAvailability.forms ? (
                        <span className="resource-loading">{wp.i18n.__( 'Loading forms...', 'cf7-telegram' )}</span>
                    ) : showFormSelector && (
                        <Select
                            className="select-picker form-picker"
                            classNamePrefix="select-picker"
                            options={availableForms.map(form => ({
                                value: form.id,
                                label: form.title
                            }))}
                            isSearchable={true}
                            placeholder={wp.i18n.__( 'Pick a form', 'cf7-telegram' )}
                            onChange={(selectedOption) => handleFormSelect({target: {value: selectedOption?.value}})}
                            isClearable
                            isDisabled={mutatingRelations}
                        />
                    )}

                    {'ready' === dataAvailability.forms && (formsForChannel.length > 0 ? (
                        <ul className={`form-list ` + (showFormSelector ? 'show-selector' : '')}>
                            {formsForChannel.map(form => (
                                <li key={form.id}>
                                    {form.title}
                                    <button
                                        className="detach-button crux detach-form-button"
                                        onClick={() => handleRemoveForm(form.id)}
                                        disabled={mutatingRelations}
                                    ></button>
                                </li>
                            ))}
                        </ul>
                    ) : showFormSelector || (
                        <span className="no-forms-found">[{wp.i18n.__( 'No forms assigned to this channel', 'cf7-telegram' )}]</span>
                    ))}
                </div>


                <div className="frame status-bar">
                    <button
                        className="remove-channel-button"
                        onClick={deleteChannel}
                        disabled={saving || mutatingRelations}>
                        {wp.i18n.__( 'Remove channel', 'cf7-telegram' )}
                    </button>
                </div>
            </div>
        </div>
    );
};

export default ChannelView;
