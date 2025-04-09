import React from 'react';

const ChannelView = ({
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
                         botsChatRelations = [],
                         handleToggleChat
                     }) => {
    const renderedChats = (botForChannel?.chats || [])
        .map(chat => {
            const relation = botsChatRelations.find(rel => rel.data.from === botForChannel.id && rel.data.to === chat.id);
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
        <div className="entity-wrapper channel-wrapper">
            <div className="channel-title">
                {isEditingTitle ? (
                    <div className="edit-title">
                        <input
                            type="text"
                            value={titleValue}
                            onChange={handleTitleChange}
                            onKeyDown={handleKeyDown}
                            onBlur={() => {}}
                            disabled={saving}
                            autoFocus
                        />
                        <button onClick={saveTitle} disabled={saving}>💾</button>
                        <button onClick={handleCancelEdit} disabled={saving}>❌</button>
                        {saving && <span>⏳ Saving...</span>}
                        {error && <p style={{ color: 'red' }}>{error}</p>}
                    </div>
                ) : (
                    <h4 onClick={handleTitleClick} style={{ cursor: 'pointer' }}>
                        {titleValue} <span style={{ marginLeft: 6, fontSize: '0.9em' }}>✏️</span>
                    </h4>
                )}
            </div>

            <div className="frame bots">
                <h5>Bot</h5>
                {botForChannel ? (
                    <div id={botForChannel.id} className="bot-for-channel">
                        <p>{botForChannel.title.rendered}</p>
                        <button
                            className="detach-button detach-bot-button"
                            onClick={handleRemoveBot}>Detach Bot</button>
                    </div>
                ) : (
                    <>
                        <p>No bot assigned to this channel</p>
                        {availableBots.length > 0 && (
                            <select onChange={handleBotSelect} defaultValue="">
                                <option value="" disabled>Select bot</option>
                                {availableBots.map(bot => (
                                    <option key={bot.id} value={bot.id}>{bot.title.rendered}</option>
                                ))}
                            </select>
                        )}
                    </>
                )}
            </div>

            <div className="frame chats">
                <h5>Chats</h5>
                {renderedChats.length > 0 ? (
                    <ul>
                        {renderedChats.map(chat => (
                            <li key={chat.id}>
                                {chat.title.rendered} ({chat.status})
                                <button onClick={() => handleToggleChat(chat.id)} style={{ marginLeft: '0.5em' }}>
                                    {chat.status === 'Active' ? 'Pause' : 'Activate'}
                                </button>
                            </li>
                        ))}
                    </ul>
                ) : (
                    <p>No chats assigned to this channel</p>
                )}
            </div>

            <div className="frame forms">
                <h5>Forms</h5>
                <button
                    className="add-button add-form-button"
                    onClick={handleAddForm}>Add Form
                </button>
                {showFormSelector && (
                    <select onChange={handleFormSelect} defaultValue="">
                        <option value="" disabled>Select form</option>
                        {availableForms.map(form => (
                            <option key={form.id} value={form.id}>{form.title}</option>
                        ))}
                    </select>
                )}

                {formsForChannel.length > 0 ? (
                    <ul>
                        {formsForChannel.map(form => (
                            <li key={form.id}>
                                {form.title}
                                <button
                                    className="detach-button detach-form-button"
                                    onClick={() => handleRemoveForm(form.id)}
                                >Detach Form</button>
                            </li>
                        ))}
                    </ul>
                ) : (
                    <p>No forms assigned to this channel</p>
                )}
            </div>
        </div>
    );
};

export default ChannelView;
