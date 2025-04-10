/* global cf7TelegramData */

import React, { useState, useEffect } from 'react';
import ChannelView from './ChannelView';
import {
    connectBot2Channel,
    connectChat2Channel,
    connectForm2Channel, deleteChannel, disconnectConnectionBot2Channel,
    disconnectConnectionChat2Channel,
    disconnectConnectionForm2Channel
} from "../utils/main";
import { apiSaveChannel} from "../utils/api";

const Channel = ({
    channel,
    forms,
    setChannels,
    form2ChannelRelations,
    setForm2ChannelRelations,
    bots,
    bot2ChannelRelations,
    chats,
    chat2ChannelRelations,
    setChat2ChannelRelations,
    bot2ChatConnections
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
        const botRelation = bot2ChannelRelations.find(r => r.data.to === channel.id);
        if (!botRelation) return setBotForChannel(null);
        const bot = bots.find(b => b.id === botRelation.data.from);
        if (!bot) return setBotForChannel(null);

        const botChatRelations = bot2ChatConnections.filter(r => r.data.from === bot.id);
        const botChats = chats
            .map(chat => {
                const relation = botChatRelations.find(r => r.data.to === chat.id);
                if (!relation) return null;

                const hasChannelRelation = chat2ChannelRelations.some(r => r.data.from === chat.id && r.data.to === channel.id);

                return {
                    ...chat,
                    muted: !!relation.data.muted,
                    status: hasChannelRelation ? 'active' : 'paused'
                };
            })
            .filter(Boolean);

        setBotForChannel({ ...bot, chats: botChats });
        setChatsForChannel(botChats.filter(chat => chat.status === 'active'));
    }, [channel.id, bots, bot2ChannelRelations, bot2ChatConnections, chats, chat2ChannelRelations]);

    useEffect(() => {
        const relatedIds = form2ChannelRelations.filter(r => r.data?.to === channel.id).map(r => r.data.from);
        const linked = forms.filter(f => relatedIds.includes(f.id));
        const unlinked = forms.filter(f => !relatedIds.includes(f.id));
        setFormsForChannel(linked);
        setAvailableForms(unlinked);
    }, [forms, form2ChannelRelations, channel.id]);

    useEffect(() => {
        const currentBotRelation = bot2ChannelRelations.find(r => r.data.to === channel.id);
        const usedBotId = currentBotRelation?.data.from;
        const unlinkedBots = bots.filter(bot => bot.id !== usedBotId);
        setAvailableBots(unlinkedBots);
    }, [bots, bot2ChannelRelations, channel.id]);

    const handleAddForm = () => setShowFormSelector(prev => !prev);

    const handleFormSelect = async (event) => {
        const formId = parseInt(event.target.value, 10);

        try {
            await connectForm2Channel(formId, channel.id, setForm2ChannelRelations)
        } catch (err) {
            console.error(err);
            alert('Something went wrong while assigning the form');
        } finally {
            setShowFormSelector(false);
        }
    };

    const handleRemoveForm = async (formId) => {
        const connection = form2ChannelRelations.find(r => r.data.from === formId && r.data.to === channel.id);

        if (
            !connection ||
            !window.confirm('Are you sure you want to remove this form from the channel?')
        )
            return;

        try {
            await disconnectConnectionForm2Channel(connection.data.id, setForm2ChannelRelations)
        } catch (err) {
            console.error(err);
            alert('Failed to remove form');
        }
    };

    const handleToggleChat = async (chatId) => {
        let connection = chat2ChannelRelations.find(r => r.data.from === chatId && r.data.to === channel.id);

        if (!connection) {
            await connectChat2Channel(chatId, channel.id, setChat2ChannelRelations);
        } else {
            await disconnectConnectionChat2Channel(connection.data.id, setChat2ChannelRelations);
        }
    };

    const handleBotSelect = async (event) => {
        const botId = parseInt(event.target.value, 10);

        try {
            await connectBot2Channel(botId, channel.id);
        } catch (err) {
            console.error(err);
            alert('Failed to assign bot');
        }
    };

    const handleRemoveBot = async () => {
        const relation = bot2ChannelRelations.find(r => r.data.to === channel.id);

        if (!relation) return;

        try {
            await disconnectConnectionBot2Channel(relation.data.id);
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
            await apiSaveChannel(channel.id, titleValue);
            setIsEditingTitle(false);
        } catch (err) {
            console.error(err);
            setError('Failed to update title');
        } finally {
            setSaving(false);
        }
    };

    const handleDeleteChannel = async () => {
        if (!window.confirm('Are you sure you want to delete this channel?')) return;

        setSaving(true);
        setError(null);
        try {
            await deleteChannel(channel.id, setChannels);
        } catch (err) {
            console.error(err);
            setError('Failed to delete channel');
        } finally {
            setSaving(false);
        }
    }

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
            bot2ChatConnections={bot2ChatConnections}
            handleToggleChat={handleToggleChat}
            deleteChannel={handleDeleteChannel}
        />
    );
};

export default Channel;
