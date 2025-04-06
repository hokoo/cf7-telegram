/* global cf7TelegramData */

import React, { useState, useEffect } from 'react';
import ChannelView from './ChannelView';

const Channel = ({
                     channel,
                     forms,
                     formsRelations,
                     setFormsRelations,
                     bots,
                     botsRelations,
                     setBotsRelations,
                     chats,
                     chatsRelations,
                     botsChatRelations
                 }) => {
    const [botForChannel, setBotForChannel] = useState(null);
    const [chatsForChannel, setChatsForChannel] = useState([]);
    const [formsForChannel, setFormsForChannel] = useState([]);
    const [availableForms, setAvailableForms] = useState([]);
    const [showFormSelector, setShowFormSelector] = useState(false);
    const [availableBots, setAvailableBots] = useState([]);

    const [isEditingTitle, setIsEditingTitle] = useState(false);
    const [titleValue, setTitleValue] = useState(channel.title.rendered);
    const [saving, setSaving] = useState(false);
    const [error, setError] = useState(null);

    useEffect(() => {
        const botRelation = botsRelations.find(r => r.data.to === channel.id);
        if (!botRelation) return setBotForChannel(null);
        const bot = bots.find(b => b.id === botRelation.data.from);
        if (!bot) return setBotForChannel(null);

        const botChatRelations = botsChatRelations.filter(r => r.data.from === bot.id);
        const botChats = chats
            .map(chat => {
                const relation = botChatRelations.find(r => r.data.to === chat.id);
                if (!relation) return null;
                return {
                    ...chat,
                    muted: !!relation.data.muted
                };
            })
            .filter(Boolean);

        setBotForChannel({ ...bot, chats: botChats });
    }, [channel.id, bots, botsRelations, botsChatRelations, chats]);

    useEffect(() => {
        const relatedChats = chatsRelations.filter(r => r.data.to === channel.id);
        const resolved = relatedChats.map(r => chats.find(c => c.id === r.data.from)).filter(Boolean);
        setChatsForChannel(resolved);
    }, [channel.id, chats, chatsRelations]);

    useEffect(() => {
        const relatedIds = formsRelations.filter(r => r.data?.to === channel.id).map(r => r.data.from);
        const linked = forms.filter(f => relatedIds.includes(f.id));
        const unlinked = forms.filter(f => !relatedIds.includes(f.id));
        setFormsForChannel(linked);
        setAvailableForms(unlinked);
    }, [forms, formsRelations, channel.id]);

    useEffect(() => {
        const currentBotRelation = botsRelations.find(r => r.data.to === channel.id);
        const usedBotId = currentBotRelation?.data.from;
        const unlinkedBots = bots.filter(bot => bot.id !== usedBotId);
        setAvailableBots(unlinkedBots);
    }, [bots, botsRelations, channel.id]);

    const handleAddForm = () => setShowFormSelector(prev => !prev);

    const handleFormSelect = async (event) => {
        const formId = parseInt(event.target.value, 10);
        try {
            const response = await fetch(cf7TelegramData.routes.relations.form2channel, {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'X-WP-Nonce': cf7TelegramData?.nonce,
                },
                body: JSON.stringify({ from: formId, to: channel.id }),
            });

            if (response.status !== 200) throw new Error('Failed to assign form');
            const newRelation = await response.json();
            setFormsRelations(prev => [...prev, { data: newRelation }]);
            setShowFormSelector(false);
        } catch (err) {
            console.error(err);
            alert('Something went wrong while assigning the form');
        }
    };

    const handleRemoveForm = async (formId) => {
        const relation = formsRelations.find(r => r.data.from === formId && r.data.to === channel.id);
        if (!relation) return;

        const confirmDelete = window.confirm('Are you sure you want to remove this form from the channel?');
        if (!confirmDelete) return;

        try {
            const response = await fetch(`${cf7TelegramData.routes.relations.form2channel}${relation.data.id}`, {
                method: 'DELETE',
                headers: {
                    'X-WP-Nonce': cf7TelegramData?.nonce,
                },
            });
            if (response.status !== 200) throw new Error('Failed to remove form');
            setFormsRelations(prev => prev.filter(r => r.data.id !== relation.data.id));
        } catch (err) {
            console.error(err);
            alert('Failed to remove form');
        }
    };

    const handleBotSelect = async (event) => {
        const botId = parseInt(event.target.value, 10);

        try {
            const response = await fetch(cf7TelegramData.routes.relations.bot2channel, {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'X-WP-Nonce': cf7TelegramData?.nonce,
                },
                body: JSON.stringify({ from: botId, to: channel.id }),
            });

            if (response.status !== 200) throw new Error('Failed to assign bot');
            const newRelation = await response.json();
            setBotsRelations(prev => [...prev.filter(r => r.data.to !== channel.id), { data: newRelation }]);
        } catch (err) {
            console.error(err);
            alert('Failed to assign bot');
        }
    };

    const handleRemoveBot = async () => {
        const relation = botsRelations.find(r => r.data.to === channel.id);
        if (!relation) return;

        const confirmRemove = window.confirm('Are you sure you want to remove this bot from the channel?');
        if (!confirmRemove) return;

        try {
            const response = await fetch(`${cf7TelegramData.routes.relations.bot2channel}${relation.data.id}`, {
                method: 'DELETE',
                headers: {
                    'X-WP-Nonce': cf7TelegramData?.nonce,
                },
            });
            if (response.status !== 200) throw new Error('Failed to remove bot');
            setBotsRelations(prev => prev.filter(r => r.data.id !== relation.data.id));
        } catch (err) {
            console.error(err);
            alert('Failed to remove bot');
        }
    };

    const handleTitleClick = () => {
        setError(null);
        setIsEditingTitle(true);
    };

    const handleTitleChange = (e) => setTitleValue(e.target.value);

    const handleCancelEdit = () => {
        setTitleValue(channel.title.rendered);
        setIsEditingTitle(false);
        setError(null);
    };

    const handleKeyDown = (e) => {
        if (e.key === 'Enter') saveTitle();
        if (e.key === 'Escape') handleCancelEdit();
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

            if (response.status !== 200) throw new Error('Failed to update title');
            setIsEditingTitle(false);
        } catch (err) {
            console.error(err);
            setError('Failed to update title');
        } finally {
            setSaving(false);
        }
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
            availableForms={availableForms}
            showFormSelector={showFormSelector}
            handleAddForm={handleAddForm}
            handleFormSelect={handleFormSelect}
            handleRemoveForm={handleRemoveForm}
            availableBots={availableBots}
            handleBotSelect={handleBotSelect}
            handleRemoveBot={handleRemoveBot}
            botsChatRelations={botsChatRelations}
        />
    );
};

export default Channel;
