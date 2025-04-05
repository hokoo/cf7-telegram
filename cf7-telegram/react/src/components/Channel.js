/* global cf7TelegramData */

import React, { useState, useEffect } from 'react';
import ChannelView from './ChannelView';

const Channel = ({ channel, forms, formsRelations, bots, botsRelations, chats, chatsRelations }) => {
    const [formsForChannel, setFormsForChannel] = useState([]);
    const [botForChannel, setBotForChannel] = useState(null);
    const [chatsForChannel, setChatsForChannel] = useState([]);
    const [isEditingTitle, setIsEditingTitle] = useState(false);
    const [titleValue, setTitleValue] = useState(channel.title.rendered);
    const [saving, setSaving] = useState(false);
    const [error, setError] = useState(null);

    useEffect(() => {
        const relatedFormsIds = formsRelations
            .filter(r => r.data.to === channel.id)
            .map(r => r.data.from);
        setFormsForChannel(forms.filter(form => relatedFormsIds.includes(form.id)));
    }, [channel.id, forms, formsRelations]);

    useEffect(() => {
        const relation = botsRelations.find(r => r.data.to === channel.id);
        setBotForChannel(relation ? bots.find(b => b.id === relation.data.from) : null);
    }, [channel.id, bots, botsRelations]);

    useEffect(() => {
        const related = chatsRelations.filter(r => r.data.to === channel.id);
        const matched = related.map(r => chats.find(chat => chat.id === r.data.from)).filter(Boolean);
        setChatsForChannel(matched);
    }, [channel.id, chats, chatsRelations]);

    const handleTitleClick = () => {
        setError(null);
        setIsEditingTitle(true);
    };

    const handleTitleChange = e => setTitleValue(e.target.value);

    const handleCancelEdit = () => {
        setTitleValue(channel.title.rendered);
        setIsEditingTitle(false);
        setError(null);
    };

    const saveTitle = async () => {
        if (titleValue.trim() === '' || titleValue === channel.title.rendered) {
            setIsEditingTitle(false);
            return;
        }

        setSaving(true);
        setError(null);

        try {
            const response = await fetch(`${cf7TelegramData.routes.channels}${channel.id}`, {
                method: 'PATCH',
                headers: {
                    'Content-Type': 'application/json',
                    'X-WP-Nonce': cf7TelegramData?.nonce,
                },
                body: JSON.stringify({ title: titleValue }),
            });

            if (!response.ok) throw new Error('Failed to update title');
            setIsEditingTitle(false);
        } catch (err) {
            console.error(err);
            setError('Failed to update title');
        } finally {
            setSaving(false);
        }
    };

    const handleKeyDown = e => {
        if (e.key === 'Enter') saveTitle();
        if (e.key === 'Escape') handleCancelEdit();
    };

    return (
        <ChannelView
            isEditingTitle={isEditingTitle}
            titleValue={titleValue}
            saving={saving}
            error={error}
            handleTitleClick={handleTitleClick}
            handleTitleChange={handleTitleChange}
            handleKeyDown={handleKeyDown}
            handleCancelEdit={handleCancelEdit}
            saveTitle={saveTitle}
            botForChannel={botForChannel}
            chatsForChannel={chatsForChannel}
            formsForChannel={formsForChannel}
        />
    );
};

export default Channel;
