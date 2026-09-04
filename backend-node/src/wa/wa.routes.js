import express from 'express'
import { createSession, listSessions, getQr, getStatus, logoutSession, getChats, getMessages, downloadMedia, sendMessage } from './wa.controller.js'

export const waRouter = express.Router()

waRouter.get('/sessions', listSessions)
waRouter.post('/sessions', createSession)
waRouter.get('/sessions/:sessionId/status', getStatus)
waRouter.get('/sessions/:sessionId/qr', getQr)
waRouter.post('/sessions/:sessionId/logout', logoutSession)
waRouter.get('/sessions/:sessionId/chats', getChats)
waRouter.get('/sessions/:sessionId/chats/:chatId/messages', getMessages)
waRouter.get('/sessions/:sessionId/messages/:messageId/media', downloadMedia)
waRouter.post('/sessions/:sessionId/chats/:chatId/messages', sendMessage)
