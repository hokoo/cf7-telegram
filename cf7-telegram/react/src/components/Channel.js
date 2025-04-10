/* global cf7TelegramData */

import React, {useState, useEffect} from 'react';
import ChannelView from './ChannelView';
import {
    connectBot2Channel,
    connectChat2Channel,
    connectForm2Channel,
    deleteChannel,
    disconnectConnectionBot2Channel,
    disconnectConnectionChat2Channel,
    disconnectConnectionForm2Channel
} from "../utils/main";
import {apiSaveChannel} from "../utils/api";

const Channel = ({
    channel,
    forms,
    setChannels,
    form2ChannelConnections,
    setForm2ChannelConnections,
    bots,
    bot2ChannelConnections,
    setBot2ChannelConnections,
    chats,
    chat2ChannelConnections,
    setChat2ChannelConnections,
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
        const botConnection = bot2ChannelConnections.find(c => c.data.to === channel.id);
        if (!botConnection) return setBotForChannel(null);
        const bot = bots.find(b => b.id === botConnection.data.from);
        if (!bot) return setBotForChannel(null);

        const botChatConnections = bot2ChatConnections.filter(c => c.data.from === bot.id);
        const botChats = chats
            .map(chat => {
                const connection = botChatConnections.find(c => c.data.to === chat.id);
                if (!connection) return null;

                const hasChannelConnection = chat2ChannelConnections.some(c => c.data.from === chat.id && c.data.to === channel.id);

                return {
                    ...chat,
                    muted: !!connection.data.muted,
                    status: hasChannelConnection ? 'active' : 'paused'
                };
            })
            .filter(Boolean);

        setBotForChannel({...bot, chats: botChats});
        setChatsForChannel(botChats.filter(chat => chat.status === 'active'));
    }, [channel.id, bots, bot2ChannelConnections, bot2ChatConnections, chats, chat2ChannelConnections]);

    useEffect(() => {
        const relatedIds = form2ChannelConnections.filter(c => c.data?.to === channel.id).map(r => r.data.from);
        const linked = forms.filter(f => relatedIds.includes(f.id));
        const unlinked = forms.filter(f => !relatedIds.includes(f.id));
        setFormsForChannel(linked);
        setAvailableForms(unlinked);
    }, [forms, form2ChannelConnections, channel.id]);

    useEffect(() => {
        const currentBotConnection = bot2ChannelConnections.find(c => c.data.to === channel.id);
        const usedBotId = currentBotConnection?.data.from;
        const unlinkedBots = bots.filter(bot => bot.id !== usedBotId);
        setAvailableBots(unlinkedBots);
    }, [bots, bot2ChannelConnections, channel.id]);

    const handleAddForm = () => setShowFormSelector(prev => !prev);

    const handleFormSelect = async (event) => {
        const formId = parseInt(event.target.value, 10);

        try {
            await connectForm2Channel(formId, channel.id, setForm2ChannelConnections)
        } catch (err) {
            console.error(err);
            alert('Something went wrong while assigning the form');
        } finally {
            setShowFormSelector(false);
        }
    };

    const handleRemoveForm = async (formId) => {
        const connection = form2ChannelConnections.find(c => c.data.from === formId && c.data.to === channel.id);

        if (
            !connection ||
            !window.confirm('Are you sure you want to remove this form from the channel?')
        )
            return;

        try {
            await disconnectConnectionForm2Channel(connection.data.id, setForm2ChannelConnections)
        } catch (err) {
            console.error(err);
            alert('Failed to remove form');
        }
    };

    const handleToggleChat = async (chatId) => {
        let connection = chat2ChannelConnections.find(c => c.data.from === chatId && c.data.to === channel.id);

        if (!connection) {
            await connectChat2Channel(chatId, channel.id, setChat2ChannelConnections);
        } else {
            await disconnectConnectionChat2Channel(connection.data.id, setChat2ChannelConnections);
        }
    };

    const handleBotSelect = async (event) => {
        const botId = parseInt(event.target.value, 10);

        try {
            await connectBot2Channel(botId, channel.id, setBot2ChannelConnections);
        } catch (err) {
            console.error(err);
            alert('Failed to assign bot');
        }
    };

    const handleRemoveBot = async () => {
        const connection = bot2ChannelConnections.find(c => c.data.to === channel.id);

        if (!connection) return;

        try {
            await disconnectConnectionBot2Channel(connection.data.id, setBot2ChannelConnections);
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
