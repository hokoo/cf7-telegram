import React from 'react';
import Select from 'react-select';

const ChannelView = ({
    channel,
    isEditingTitle,
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
    getToggleButtonLabel
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

    return (
        <div className="entity-container channel" key={channel.id} id={`channel-${channel.id}`}>
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
                            {botForChannel ? (
                                <div data-Bot-Id={botForChannel.id} className={`bot-for-channel ` + (botForChannel?.online ? 'online' : 'offline')}>
                                    <span>{botForChannel.title.rendered}</span>
                                    <button
                                        className="detach-button detach-bot-button crux"
                                        onClick={handleRemoveBot}
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
                                            placeholder="Pick bot"
                                            onChange={(selectedOption) => handleBotSelect({target: {value: selectedOption?.value}})}
                                            isClearable
                                        />
                                    )}
                                </>
                            )}
                        </div>
                    </div>
                </div>

                <div className="frame chats">
                    {renderedChats.length > 0 ? (
                        <ul>
                            {renderedChats.map(chat => (
                                <li
                                    key={chat.id}
                                    className={`chat chat-${chat.id} ${chat.status.toLowerCase()}`}
                                    onClick={() => handleToggleChat(chat.id, chat.status)}
                                    title={getToggleButtonLabel(chat.status)}
                                >
                                    <span className={`chat-username`}>{chat.title.rendered}</span>
                                </li>
                            ))}
                        </ul>
                    ) : (
                        <span className="no-chats-found">[No chats assigned to this channel]</span>
                    )}
                </div>

                <div className="frame forms">
                    <button
                        className="add-button add-form-button"
                        onClick={handleAddForm}
                    >
                        {!showFormSelector ? (`Add Form`) : (`Cancel`)}
                    </button>
                    {showFormSelector && (
                        <Select
                            className="select-picker form-picker"
                            classNamePrefix="select-picker"
                            options={availableForms.map(form => ({
                                value: form.id,
                                label: form.title
                            }))}
                            isSearchable={true}
                            placeholder="Pick Form"
                            onChange={(selectedOption) => handleFormSelect({target: {value: selectedOption?.value}})}
                            isClearable
                        />
                    )}

                    {formsForChannel.length > 0 ? (
                        <ul className={`form-list ` + (showFormSelector ? 'show-selector' : '')}>
                            {formsForChannel.map(form => (
                                <li key={form.id}>
                                    {form.title}
                                    <button
                                        className="detach-button crux detach-form-button"
                                        onClick={() => handleRemoveForm(form.id)}
                                    ></button>
                                </li>
                            ))}
                        </ul>
                    ) : showFormSelector || (
                        <span className="no-forms-found">[No forms assigned to this channel]</span>
                    )}
                </div>


                <div className="frame status-bar">
                    <button
                        className="remove-channel-button"
                        onClick={deleteChannel}
                        disabled={saving}>
                        Remove Channel
                    </button>
                </div>
            </div>
        </div>
    );
};

export default ChannelView;
