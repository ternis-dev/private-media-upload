# syntax=docker/dockerfile:1
FROM node:22-alpine AS build
WORKDIR /web
COPY package.json pnpm-lock.yaml* package-lock.json* ./
RUN npm install --no-audit --no-fund || true
COPY . .
RUN npm run build

FROM node:22-alpine
WORKDIR /web
ENV NODE_ENV=production
COPY --from=build /web ./
RUN npm install --omit=dev --no-audit --no-fund || true
EXPOSE 3000
CMD ["npm", "start"]
