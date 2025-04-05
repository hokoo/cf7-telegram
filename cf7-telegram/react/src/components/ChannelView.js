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
                         formsForChannel = []
                     }) => {
    return (
        <div className="cf7tg-channel-wrapper">
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

            <div className="bots">
                <h5>Bot</h5>
                {botForChannel ? (
                    <div id={botForChannel.id} className="bot-for-channel">
                        <p>{botForChannel.title.rendered}</p>
                        <span className="bot-token">token: {botForChannel.token}</span>
                    </div>
                ) : (
                    <p>No bot assigned to this channel</p>
                )}
            </div>

            <div className="chats">
                <h5>Chats</h5>
                {chatsForChannel.length > 0 ? (
                    <ul>
                        {chatsForChannel.map(chat => (
                            <li key={chat.id}>{chat.title.rendered}</li>
                        ))}
                    </ul>
                ) : (
                    <p>No chats assigned to this channel</p>
                )}
            </div>

            <div className="forms">
                <h5>Forms</h5>
                {formsForChannel.length > 0 ? (
                    <ul>
                        {formsForChannel.map(form => (
                            <li key={form.id}>{form.title}</li>
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
