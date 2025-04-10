/* global cf7TelegramData */

import {
    apiConnectBot2Channel,
    apiConnectChat2Channel,
    apiConnectForm2Channel,
    apiCreateChannel, apiDeleteChannel,
    apiDisconnectBot2Channel,
    apiDisconnectBot2Chat,
    apiDisconnectChat2Channel,
    apiDisconnectForm2Channel,
    apiSetBot2ChatRelationStatus,
} from './api';

import chat2ChannelRelations from "../App";

export const connectBot2Channel = async (botId, channelId, setBot2ChannelRelations) => {
    const result = await apiConnectBot2Channel(botId, channelId);
    if (result) {
        setBot2ChannelRelations(prev => [...prev, { data: result }]);
    }
}

export const disconnectConnectionBot2Channel = async (connectionId, setBot2ChannelRelations) => {
    const success = await apiDisconnectBot2Channel(connectionId);
    if (success) {
        setBot2ChannelRelations(prev => prev.filter(r => r.data.id !== connectionId));
    }
}

export const connectForm2Channel = async (formId, channelId, setForm2ChannelRelations) => {
    const result = await apiConnectForm2Channel(formId, channelId);
    if (result) {
        setForm2ChannelRelations(prev => [...prev, { data: result }]);
    }
}

export const disconnectConnectionForm2Channel = async (connectionId, setForm2ChannelRelations) => {
    const success = await apiDisconnectForm2Channel(connectionId);
    if (success) {
        setForm2ChannelRelations(prev => prev.filter(r => r.data.id !== connectionId));
    }
}

export const connectChat2Channel = async (chatId, channelId, setChat2ChannelRelations) => {
    const result = await apiConnectChat2Channel(chatId, channelId);
    if (result) {
        setChat2ChannelRelations(prev => [...prev, { data: result }]);
    }
};

export const disconnectChat2Channel = async (chatId, channelId, setChat2ChannelRelations) => {
    const connection = chat2ChannelRelations.find(r => r.data.from === chatId && r.data.to === channelId);

    if (connection) {
        await disconnectConnectionChat2Channel(connection.id, setChat2ChannelRelations)
    }
}

export const disconnectConnectionChat2Channel = async (connectionId, setChat2ChannelRelations) => {
    const success = await apiDisconnectChat2Channel(connectionId);
    if (success) {
        setChat2ChannelRelations(prev => prev.filter(r => r.data.id !== connectionId));
    }
};

export const setBot2ChatRelationStatus = async (connectionId, status, setBot2ChatConnections) => {
    const result = await apiSetBot2ChatRelationStatus(connectionId, status);
    if (result) {
        setBot2ChatConnections(prev => {
            const updatedRelations = [...prev];
            const index = updatedRelations.findIndex(r => r.data.id === connectionId);
            if (index !== -1) {
                updatedRelations[index].data.meta.status[0] = status;
            }
            return updatedRelations;
        });
    }

    return null;
}

export const disconnectConnectionBot2Chat = async (connectionId, setBot2ChatConnections) => {
    const success = await apiDisconnectBot2Chat(connectionId);
    if (success) {
        setBot2ChatConnections(prev => prev.filter(r => r.data.id !== connectionId));
    }
}

export const createChannel = async (name, setChannels) => {
    let createdChannel = await apiCreateChannel(name);
    if (createdChannel) {
        setChannels(prev => [...prev, createdChannel]);
    }
}

export const deleteChannel = async (channelId, setChannels) => {
    const success = await apiDeleteChannel(channelId);
    if (success) {
        setChannels(prev => prev.filter(channel => channel.id !== channelId));
    }
}
