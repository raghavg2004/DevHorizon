FROM node:20-alpine

WORKDIR /app

# Install native dependencies for sqlite3 build
RUN apk add --no-cache python3 make g++

# Copy package manifests and install dependencies
COPY package*.json ./
RUN npm ci --only=production

# Copy application source
COPY . .

# Ensure storage directories exist
RUN mkdir -p /app/data /app/uploads

# Expose production port
EXPOSE 3000

ENV NODE_ENV=production
ENV PORT=3000

CMD ["node", "server.js"]

